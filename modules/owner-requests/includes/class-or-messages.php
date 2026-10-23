<?php
/**
 * Owner Requests: the message templates.
 *
 * Plain functions of plain data, so the unit tests can render every message
 * without WordPress. Each returns subject, text and html. No owner email or
 * phone appears in any trainer message: a trainer sees those only after
 * opening the request page.
 *
 * Joe approves each template once; the module sends within it.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Messages {

    const SPECIALTY_LABELS = array(
        'potty' => 'Potty training', 'puppy' => 'Puppy training', 'recall' => 'Recall',
        'leash' => 'Leash pulling', 'stealing' => 'Stealing food', 'fearfulness' => 'Fearfulness',
        'biting' => 'Biting or nipping', 'reactivity' => 'Reactivity or aggression',
        'guarding' => 'Resource guarding', 'separation' => 'Separation anxiety',
        'jumping' => 'Jumping on people', 'barking' => 'Barking', 'basic' => 'Basic obedience',
    );

    const AGE_LABELS = array(
        'puppy' => 'Puppy (under 1 year)', 'adolescent' => 'Adolescent (1 to 2 years)',
        'adult' => 'Adult (2 to 8 years)', 'senior' => 'Senior (8 years or more)',
    );

    const FORMAT_LABELS = array(
        'any' => 'Any kind of training', 'home' => 'In-home training', 'group' => 'A group class',
        'board' => 'Board and train', 'video' => 'Video calls',
    );

    /** "In-home training or a group class" for the kinds an owner ticked; "Any kind of training" when none. */
    public static function format_label( $format ) {
        $labels = array();
        foreach ( explode( ',', (string) $format ) as $f ) {
            if ( 'any' !== $f && isset( self::FORMAT_LABELS[ $f ] ) ) {
                $labels[] = $labels ? lcfirst( self::FORMAT_LABELS[ $f ] ) : self::FORMAT_LABELS[ $f ];
            }
        }
        if ( ! $labels ) {
            return self::FORMAT_LABELS['any'];
        }
        $last = array_pop( $labels );
        return $labels ? implode( ', ', $labels ) . ' or ' . $last : $last;
    }

    const VET_LINE = 'If your dog is hurt or sick, call your vet first. A trainer cannot treat a medical problem.';

    private static function h( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * The words that introduce the trainer list. Honest about trainers who were
     * matched only because they offer video sessions (they are not near the owner).
     * $trainers: each with an optional by_video flag. $where: "you" or a ZIP.
     */
    public static function list_intro( array $trainers, $where ) {
        $n = count( $trainers );
        $v = 0;
        $other = 0;
        foreach ( $trainers as $t ) {
            if ( ! empty( $t['by_video'] ) ) {
                $v++;
            } elseif ( ! empty( $t['far'] ) ) {
                $other++;
            }
        }
        $noun = 1 === $n ? '1 trainer' : $n . ' trainers';
        if ( 0 === $v && 0 === $other ) {
            return $noun . ' near ' . $where . ':';
        }
        if ( $v === $n ) {
            return $noun . ( 1 === $n ? ' who offers' : ' who offer' ) . ' video sessions:';
        }
        return $noun . ':';
    }

    /** The tag after a trainer's name when they were not matched on distance. */
    public static function item_tag( array $t ) {
        if ( ! empty( $t['by_video'] ) ) {
            return ' - video sessions';
        }
        if ( ! empty( $t['far'] ) ) {
            return ' - the trainer whose page you were on';
        }
        return '';
    }

    public static function specialty_list( array $keys ) {
        $out = array();
        foreach ( $keys as $k ) {
            if ( isset( self::SPECIALTY_LABELS[ $k ] ) ) {
                $out[] = self::SPECIALTY_LABELS[ $k ];
            }
        }
        return implode( ', ', $out );
    }

    /** Where the email logo lives (a PNG, since many inboxes block SVG). */
    public static function logo_url() {
        $base = defined( 'DIRECTORY_HELPERS_URL' ) ? DIRECTORY_HELPERS_URL : 'https://goodydoggy.com/wp-content/plugins/directory-helpers/';
        return $base . 'modules/owner-requests/assets/email-logo.png';
    }

    /** The page that explains requests to trainers. */
    const TRAINER_INFO_URL = 'https://goodydoggy.com/trainer-requests/';

    private static function wrap( $title, $body_html, $footer_html ) {
        return '<!doctype html><html><body style="margin:0;padding:0;background:#ebf7fe;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ebf7fe;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:14px;border:1px solid #b4e0fd;overflow:hidden;">'
            . '<tr><td align="left" bgcolor="#0572bd" style="background:#0572bd;padding:18px 28px;border-radius:14px 14px 0 0;"><a href="https://goodydoggy.com/" style="text-decoration:none;"><img src="' . self::h( self::logo_url() ) . '" width="120" height="49" alt="Goody Doggy" style="display:block;border:0;width:120px;height:auto;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;"></a></td></tr>'
            . '<tr><td style="padding:26px 28px 8px 28px;font-family:Arial,Helvetica,sans-serif;font-size:21px;line-height:28px;font-weight:bold;color:#034c7c;">' . self::h( $title ) . '</td></tr>'
            . '<tr><td style="padding:8px 28px 24px 28px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1b2b3a;">' . $body_html . '</td></tr>'
            . '<tr><td style="padding:16px 28px 24px 28px;background:#f4f7fa;border-top:1px solid #dcf1fe;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#5a6a7b;">' . $footer_html . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function button( $url, $label ) {
        return '<p style="margin:20px 0;"><a href="' . self::h( $url ) . '" style="display:inline-block;background:#0572bd;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">' . self::h( $label ) . '</a></p>';
    }

    private static function quote_html( $text ) {
        return '<blockquote style="margin:12px 0;padding:8px 16px;border-left:4px solid #ffd64f;background:#f4f7fa;color:#1b2b3a;">' . nl2br( self::h( $text ) ) . '</blockquote>';
    }

    /**
     * Trainer lead notice (lane A).
     *
     * A trainer's first request opens with Joe's short introduction, since most
     * trainers have never heard of Goody Doggy; later requests skip it.
     *
     * $d: trainer_name, city, distance, text, specialties[], dog_age, format, flags[], notified_count,
     *     open_url, optout_url, postal, profile_url, first.
     */
    public static function trainer_notice( array $d ) {
        $first   = ! isset( $d['first'] ) || ! empty( $d['first'] );
        $where   = $d['city'] ? 'near ' . $d['city'] : 'near ZIP ' . $d['zip'];
        $subject = ( $first ? 'A dog owner ' : 'Another dog owner ' ) . $where . ' is looking for a trainer';
        $age     = isset( self::AGE_LABELS[ $d['dog_age'] ] ) ? self::AGE_LABELS[ $d['dog_age'] ] : 'Not given';
        $fmt     = self::format_label( $d['format'] );
        $help    = self::specialty_list( $d['specialties'] );
        $miles   = (int) round( $d['distance'] );
        $dist    = $miles <= 1 ? 'about 1 mile from your business' : 'about ' . $miles . ' miles from your business';
        if ( ! empty( $d['by_video'] ) ) {
            $dist = 'not a factor. The owner is open to video sessions and your listing offers them';
        }
        $profile = isset( $d['profile_url'] ) ? (string) $d['profile_url'] : '';

        $extra_lines = array();
        if ( in_array( 'aggression', $d['flags'], true ) ) {
            $extra_lines[] = 'The owner mentioned a bite or aggression. You are getting this because you hold a behavior credential.';
        }
        if ( in_array( 'medical', $d['flags'], true ) ) {
            $extra_lines[] = 'The owner mentioned a health problem. We suggested seeing a vet.';
        }
        $sent_to = (int) $d['notified_count'] <= 1 ? 'You are the only trainer from our directory who got this request.' : (int) $d['notified_count'] . ' trainers got this request.';

        $intro = 'I\'m Joe, and I run Goody Doggy, a free directory that helps dog owners find trainers near them. We built your business a free listing from its public info.';
        $lead  = $first
            ? 'A dog owner ' . $where . ' just asked for training help, and it sounds like the kind of training you do. Here\'s what they wrote:'
            : 'Another dog owner ' . $where . ' asked for training help on Goody Doggy, and it sounds like the kind of training you do. Here\'s what they wrote:';
        $free  = $first
            ? array( 'This request is free. There\'s no fee and no commission.', 'Reply to the owner directly, and you don\'t need to get back to me. I\'m just playing matchmaker.' )
            : array( 'Same as before, there\'s no fee. Reply to the owner directly, and you don\'t need to get back to me.' );

        $text  = 'Hi ' . $d['trainer_name'] . ",\n\n";
        if ( $first ) {
            $text .= $intro . ( $profile ? ' See your listing: ' . $profile : '' ) . "\n\n";
        }
        $text .= $lead . "\n\n\"" . $d['text'] . "\"\n\n";
        if ( $help ) {
            $text .= 'Help with: ' . $help . "\n";
        }
        $text .= 'Dog age: ' . $age . "\n";
        $text .= 'Training they want: ' . $fmt . "\n";
        if ( empty( $d['unplaced'] ) ) {
            $text .= 'Distance: ' . $dist . "\n";
        }
        foreach ( $extra_lines as $l ) {
            $text .= $l . "\n";
        }
        $text .= "\n" . $sent_to . "\n";
        $text .= "\nRead and reply:\n" . $d['open_url'] . "\n\n";
        $text .= implode( "\n\n", $free ) . "\n\n";
        $text .= "Joe Fletcher\nOwner, Goody Doggy\n\n";
        $text .= '--' . "\nHow Goody Doggy requests work: " . self::TRAINER_INFO_URL . "\n";
        $text .= 'Don\'t want these? Stop requests like this: ' . $d['optout_url'] . "\n";
        $text .= "You got this because your business is listed on Goody Doggy and a dog owner asked for a trainer.\n" . $d['postal'] . "\n";

        $p     = '<p style="margin:0 0 14px 0;">';
        $body  = $p . 'Hi ' . self::h( $d['trainer_name'] ) . ',</p>';
        if ( $first ) {
            $body .= $p . self::h( $intro ) . ( $profile ? ' <a href="' . self::h( $profile ) . '" style="color:#0572bd;font-weight:bold;">See your listing</a>' : '' ) . '</p>';
        }
        $body .= $p . self::h( $lead ) . '</p>';
        $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ebf7fe;border-radius:12px;border-left:5px solid #ffd64f;"><tr><td style="padding:16px 18px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1b2b3a;">'
            . '<p style="margin:0 0 12px 0;font-style:italic;">' . nl2br( self::h( $d['text'] ) ) . '</p>'
            . '<p style="margin:0;font-size:15px;">';
        if ( $help ) {
            $body .= '<strong>Help with:</strong> ' . self::h( $help ) . '<br>';
        }
        $body .= '<strong>Dog age:</strong> ' . self::h( $age ) . '<br><strong>Training they want:</strong> ' . self::h( $fmt ) . ( empty( $d['unplaced'] ) ? '<br><strong>Distance:</strong> ' . self::h( $dist ) : '' ) . '</p>'
            . '<p style="margin:12px 0 0 0;font-size:14px;color:#5a6a7b;">' . self::h( $sent_to ) . '</p>'
            . '</td></tr></table>';
        foreach ( $extra_lines as $l ) {
            $body .= '<p style="margin:12px 0 0 0;">' . self::h( $l ) . '</p>';
        }
        $body .= self::button( $d['open_url'], 'Read and reply' );
        foreach ( $free as $line ) {
            $body .= $p . self::h( $line ) . '</p>';
        }
        $body .= '<p style="margin:0;">Joe Fletcher<br><span style="color:#5a6a7b;">Owner, Goody Doggy</span></p>';

        $footer = '<a href="' . self::h( self::TRAINER_INFO_URL ) . '" style="color:#0572bd;">How Goody Doggy requests work</a> &nbsp;|&nbsp; '
            . '<a href="' . self::h( $d['optout_url'] ) . '" style="color:#5a6a7b;">Stop requests like this</a><br>'
            . 'You got this because your business is listed on Goody Doggy and a dog owner asked for a trainer.<br>' . self::h( $d['postal'] );

        return array( 'subject' => $subject, 'text' => $text, 'html' => self::wrap( $first ? 'A dog owner needs a trainer' : 'Another dog owner needs a trainer', $body, $footer ) );
    }

    /**
     * Owner receipt.
     *
     * $d: first_name, zip, text, trainers[] (name, city, url), flags[], delete_url, fallback_url, postal.
     */
    public static function owner_receipt( array $d ) {
        $n    = count( $d['trainers'] );
        $vet  = ( in_array( 'medical', $d['flags'], true ) || in_array( 'aggression', $d['flags'], true ) ) ? self::VET_LINE : '';

        if ( $n > 0 ) {
            $subject = 'We sent your dog training request';
            $text    = 'Hi ' . $d['first_name'] . ",\n\n";
            $text   .= 'We sent your request to ' . self::list_intro( $d['trainers'], $d['zip'] ) . "\n";
            foreach ( $d['trainers'] as $t ) {
                $text .= '- ' . $t['name'] . ( $t['city'] ? ' (' . $t['city'] . ')' : '' ) . self::item_tag( $t ) . ': ' . $t['url'] . "\n";
            }
            $text .= "\nA trainer who opens your request sees your first name, your email, and your phone if you gave one. They reply to you directly.\n";
            if ( $vet ) {
                $text .= "\n" . $vet . "\n";
            }
            $text .= "\nWhat you wrote:\n\"" . $d['text'] . "\"\n\n";

            $body  = '<p style="margin:0 0 12px 0;">Hi ' . self::h( $d['first_name'] ) . ',</p>';
            $body .= '<p style="margin:0 0 8px 0;">We sent your request to ' . self::h( self::list_intro( $d['trainers'], $d['zip'] ) ) . '</p><ul style="margin:0 0 12px 0;padding-left:20px;">';
            foreach ( $d['trainers'] as $t ) {
                $body .= '<li><a href="' . self::h( $t['url'] ) . '" style="color:#0572bd;">' . self::h( $t['name'] ) . '</a>' . ( $t['city'] ? ' (' . self::h( $t['city'] ) . ')' : '' ) . self::h( self::item_tag( $t ) ) . '</li>';
            }
            $body .= '</ul><p style="margin:0 0 12px 0;">A trainer who opens your request sees your first name, your email, and your phone if you gave one. They reply to you directly.</p>';
            if ( $vet ) {
                $body .= '<p style="margin:0 0 12px 0;font-weight:bold;">' . self::h( $vet ) . '</p>';
            }
            $body .= '<p style="margin:0;font-weight:bold;">What you wrote</p>' . self::quote_html( $d['text'] );
        } else {
            $subject = 'We could not send your dog training request yet';
            $text    = 'Hi ' . $d['first_name'] . ",\n\n";
            $text   .= 'No trainer near ' . $d['zip'] . " takes requests through Goody Doggy yet, so we did not send yours.\n\n";
            $text   .= "You can still look at the trainers listed near you and contact them yourself:\n" . $d['fallback_url'] . "\n";
            if ( $vet ) {
                $text .= "\n" . $vet . "\n";
            }

            $body  = '<p style="margin:0 0 12px 0;">Hi ' . self::h( $d['first_name'] ) . ',</p>';
            $body .= '<p style="margin:0 0 12px 0;">No trainer near ' . self::h( $d['zip'] ) . ' takes requests through Goody Doggy yet, so we did not send yours.</p>';
            $body .= '<p style="margin:0 0 12px 0;">You can still look at the trainers listed near you and contact them yourself.</p>' . self::button( $d['fallback_url'], 'See trainers near you' );
            if ( $vet ) {
                $body .= '<p style="margin:0 0 12px 0;font-weight:bold;">' . self::h( $vet ) . '</p>';
            }
        }

        $footer = self::h( $d['postal'] );
        $text   = rtrim( $text ) . "\n\n--\n" . $d['postal'] . "\n";
        return array( 'subject' => $subject, 'text' => $text, 'html' => self::wrap( $subject, $body, $footer ) );
    }

    /**
     * Owner confirm message for a held (amber) request.
     *
     * $d: first_name, zip, confirm_url, delete_url, postal.
     */
    public static function owner_confirm( array $d ) {
        $subject = 'Confirm your dog training request';
        $text    = 'Hi ' . $d['first_name'] . ",\n\n";
        $text   .= 'You asked trainers near ' . $d['zip'] . " for help. We have not sent your request yet.\n\n";
        $text   .= "To send it, confirm here:\n" . $d['confirm_url'] . "\n\n";
        $text   .= "If you did not ask for this, ignore this email and nothing will be sent.\n\n";
        $text   .= '--' . "\n" . $d['postal'] . "\n";

        $body  = '<p style="margin:0 0 12px 0;">Hi ' . self::h( $d['first_name'] ) . ',</p>';
        $body .= '<p style="margin:0 0 12px 0;">You asked trainers near ' . self::h( $d['zip'] ) . ' for help. We have not sent your request yet.</p>';
        $body .= self::button( $d['confirm_url'], 'Send my request' );
        $body .= '<p style="margin:0;">If you did not ask for this, ignore this email and nothing will be sent.</p>';
        $footer = self::h( $d['postal'] );
        return array( 'subject' => $subject, 'text' => $text, 'html' => self::wrap( $subject, $body, $footer ) );
    }

    /** Short note to a trainer when a request is withdrawn. */
    public static function withdrawn_trainer( array $d ) {
        $subject = 'Dog training request withdrawn';
        $text    = 'Hi ' . $d['trainer_name'] . ",\n\nThe dog owner withdrew the request we sent you. You do not need to reply.\n\n--\n" . $d['postal'] . "\n";
        $body    = '<p style="margin:0 0 12px 0;">Hi ' . self::h( $d['trainer_name'] ) . ',</p><p style="margin:0;">The dog owner withdrew the request we sent you. You do not need to reply.</p>';
        return array( 'subject' => $subject, 'text' => $text, 'html' => self::wrap( $subject, $body, self::h( $d['postal'] ) ) );
    }

    /** Short note to the owner when a request is withdrawn. */
    public static function withdrawn_owner( array $d ) {
        $subject = 'Your dog training request was withdrawn';
        $text    = 'Hi ' . $d['first_name'] . ",\n\nWe withdrew your dog training request and informed those trainers. Nothing else will be sent.\n\n--\n" . $d['postal'] . "\n";
        $body    = '<p style="margin:0 0 12px 0;">Hi ' . self::h( $d['first_name'] ) . ',</p><p style="margin:0;">We withdrew your dog training request and informed those trainers. Nothing else will be sent.</p>';
        return array( 'subject' => $subject, 'text' => $text, 'html' => self::wrap( $subject, $body, self::h( $d['postal'] ) ) );
    }
}
