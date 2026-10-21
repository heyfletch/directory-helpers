<?php
/**
 * Owner Requests: Gate B screen (pure PHP, unit-tested).
 *
 * The plan screens each request with one TypeSafe Jev call through n8n. This
 * is the local keyword screen that runs instead until that workflow exists. It
 * returns the same answer shape (risk tier, flags, primary specialty, dog age)
 * so the Jev call can replace it through the `dh_or_screen` filter, and any
 * failure there falls back to amber.
 *
 * Tiers: green routes now, amber waits for the owner to confirm by link,
 * red is dropped and kept 30 days for audit.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Screen {

    const VERSION = 'local-1';

    /** The 13 specialties the site stores on a profile (ACF program_specialties). */
    const SPECIALTIES = array(
        'potty', 'puppy', 'recall', 'leash', 'stealing', 'fearfulness', 'biting',
        'reactivity', 'guarding', 'separation', 'jumping', 'barking', 'basic',
    );

    /** Keyword patterns for each specialty, matched against the lowercased text. */
    const SPECIALTY_PATTERNS = array(
        'potty'       => '/\b(potty|pee|peeing|poop|pooping|house\s?train\w*|housebreak\w*|house[- ]break\w*|accidents? in the house|crate train\w*)\b/',
        'puppy'       => '/\b(puppy|puppies|pup|new dog|just got (?:a|my) dog)\b/',
        'recall'      => '/\b(recall|come when (?:called|i call)|won\'?t come|runs? away|run off|off[- ]leash|bolts?)\b/',
        'leash'       => '/\b(leash|pulls?|pulling|walks? (?:him|her|them)|on walks|heel)\b/',
        'stealing'    => '/\b(steal\w*|counter[- ]?surf\w*|gets? into the trash|eats? (?:food|socks|things) off|snatch\w*)\b/',
        'fearfulness' => '/\b(afraid|scared|fear\w*|nervous|anxious|anxiety|shy|timid|hides?|thunder|fireworks|skittish)\b/',
        'biting'      => '/\b(nip\w*|mouth\w*|chew\w* on (?:hands|people|us)|puppy bit\w*)\b/',
        'reactivity'  => '/\b(reactive|reactivity|lunges?|lunging|aggress\w*|attack\w*|fights?|fighting|growls?|growling|snaps?|snapp\w*)\b/',
        'guarding'    => '/\b(guard\w*|resource guard\w*|protects? (?:his|her|the) (?:food|toys?|bowl|bed)|growls? (?:over|at me when i (?:touch|take)))\b/',
        'separation'  => '/\b(separation|home alone|left alone|alone (?:at home|all day)|howls? when (?:i|we) leave|destroys? (?:the )?(?:house|crate)|panics? when (?:i|we) leave)\b/',
        'jumping'     => '/\b(jump\w*)\b/',
        'barking'     => '/\b(bark\w*|howl\w*|whin\w+|yap\w*)\b/',
        'basic'       => '/\b(obedience|basic (?:commands|training|manners)|sit|stay|down|manners|listen\w*|doesn\'?t listen|won\'?t listen|train(?:ed|ing)? my dog)\b/',
    );

    /** Words that show the text is about a dog at all. */
    const DOG_SIGNAL = '/\b(dog|dogs|pup|puppy|puppies|canine|lab|labrador|retriever|shepherd|poodle|bulldog|terrier|beagle|husky|doodle|chihuahua|rottweiler|pit ?bull|collie|corgi|boxer|hound|mutt|rescue|leash|bark\w*|crate|walks?|fetch|trainer|training|obedience)\b/';

    /** Advertisement and spam markers. A hit drops the request. */
    const AD_PATTERN = '/\b(seo|backlinks?|guest post|link building|crypto\w*|bitcoin|forex|casino|viagra|cialis|payday loans?|loan offer|make money|work from home|buy now|click here|limited offer|promo code|discount code|free trial|whatsapp|telegram|web design|digital marketing|marketing services|lead generation|rank (?:your|on) (?:site|website|google)|crypto|nft)\b/';

    /** Threat and abuse language. A hit drops the request. */
    const THREAT_PATTERN = '/\b(kill (?:you|him|her|them|the trainer|every)|i will (?:hurt|kill|find|ruin)|burn (?:your|the) (?:house|business)|you (?:idiot|moron|bitch|bastard)|fuck you|kys|bomb|shoot (?:you|him|her|them)|poison (?:the|a|my neighbo\w+) dog|beat (?:my|the) dog|torture)\b/';

    /** Aggression or a bite to a person. A hit routes only to credentialed trainers. */
    const AGGRESSION_PATTERN = '/\b(aggress\w*|attack\w*|drew blood|broke (?:the )?skin|mauled|bit (?:my|a|the|our|his|her|someone|somebody|me|him|her|them|a child|the kid|kids?)|bites? (?:people|kids?|children|strangers|guests|visitors|me|my|the)|snapped at (?:me|my|a|the|kids?|children|someone)|bitten)\b/';

    /** A health problem. A hit routes only to credentialed trainers and adds a see-your-vet line. */
    const MEDICAL_PATTERN = '/\b(vet|veterinar\w*|seizure\w*|vomit\w*|bleed\w*|injur\w*|limp\w*|poison\w*|ate (?:chocolate|grapes|a sock)|swallowed|emergency|medication|medicine|pain|in pain|diarrh\w*|sick|illness|lump|infection|surgery|cancer|dying)\b/';

    /**
     * Screen one request.
     *
     * @param string   $text     Cleaned problem text.
     * @param string[] $chips    Specialty keys the owner ticked.
     * @param string   $dog_age  Owner's pick: puppy, adolescent, adult, senior or ''.
     * @return array{tier:string,reasons:string[],flags:string[],specialties:string[],primary:string,dog_age:string,version:string}
     */
    public static function screen( $text, array $chips = array(), $dog_age = '' ) {
        $lower   = strtolower( (string) $text );
        $reasons = array();
        $flags   = array();
        $tier    = 'green';

        $chips = array_values( array_intersect( $chips, self::SPECIALTIES ) );

        if ( preg_match( self::THREAT_PATTERN, $lower ) ) {
            $tier      = 'red';
            $reasons[] = 'threat_or_abuse';
        }
        if ( preg_match( self::AD_PATTERN, $lower ) ) {
            $tier      = 'red';
            $reasons[] = 'advertisement';
        }

        $has_dog = (bool) preg_match( self::DOG_SIGNAL, $lower ) || ! empty( $chips ) || ( '' !== $dog_age && 'unsure' !== $dog_age );
        if ( 'red' !== $tier && ! $has_dog ) {
            $tier      = 'amber';
            $reasons[] = 'no_dog_signal';
        }

        if ( preg_match( self::AGGRESSION_PATTERN, $lower ) || ( in_array( 'reactivity', $chips, true ) && preg_match( '/\b(bit|bite|attack\w*|aggress\w*)\b/', $lower ) ) ) {
            $flags[] = 'aggression';
        }
        if ( preg_match( self::MEDICAL_PATTERN, $lower ) ) {
            $flags[] = 'medical';
        }

        // Specialties: ticked chips first, then keyword hits.
        $found = $chips;
        foreach ( self::SPECIALTY_PATTERNS as $key => $pattern ) {
            if ( ! in_array( $key, $found, true ) && preg_match( $pattern, $lower ) ) {
                $found[] = $key;
            }
        }
        if ( in_array( 'aggression', $flags, true ) && ! in_array( 'reactivity', $found, true ) ) {
            $found[] = 'reactivity';
        }

        $primary = $found ? $found[0] : '';

        return array(
            'tier'        => $tier,
            'reasons'     => array_values( array_unique( $reasons ) ),
            'flags'       => $flags,
            'specialties' => $found,
            'primary'     => $primary,
            'dog_age'     => self::dog_age_group( $lower, $dog_age ),
            'version'     => self::VERSION,
        );
    }

    /**
     * 'puppy', 'adult' or '' (unknown). The owner's pick wins; the text fills a gap.
     * Adolescent and senior owner picks map to the profile values of the same name.
     */
    public static function dog_age_group( $lower_text, $picked = '' ) {
        if ( in_array( $picked, array( 'puppy', 'adolescent', 'adult', 'senior' ), true ) ) {
            return $picked;
        }
        if ( preg_match( '/\b(puppy|puppies|pup)\b/', $lower_text ) ) {
            return 'puppy';
        }
        if ( preg_match( '/\b(\d{1,2})\s*(?:weeks?|wks?)\s*old\b/', $lower_text ) ) {
            return 'puppy';
        }
        if ( preg_match( '/\b(\d{1,2})\s*(?:months?|mos?)\s*old\b/', $lower_text, $m ) ) {
            return ( (int) $m[1] < 12 ) ? 'puppy' : 'adolescent';
        }
        if ( preg_match( '/\b(\d{1,2})\s*(?:years?|yrs?|y\/o)\s*old\b/', $lower_text, $m ) ) {
            $y = (int) $m[1];
            if ( $y < 1 ) { return 'puppy'; }
            if ( $y < 2 ) { return 'adolescent'; }
            if ( $y < 8 ) { return 'adult'; }
            return 'senior';
        }
        return '';
    }
}
