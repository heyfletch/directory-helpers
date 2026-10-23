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

    private static function wrap( $title, $body_html, $footer_html ) {
        return '<!doctype html><html><body style="margin:0;padding:0;background:#f4f7fa;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fa;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;border:1px solid #dce0e5;">'
            . '<tr><td style="padding:28px 28px 8px 28px;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;color:#034c7c;">' . self::h( $title ) . '</td></tr>'
            . '<tr><td style="padding:8px 28px 24px 28px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1b2b3a;">' . $body_html . '</td></tr>'
            . '<tr><td style="padding:16px 28px 24px 28px;border-top:1px solid #dce0e5;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#5a6a7b;">' . $footer_html . '</td></tr>'
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
     * $d: trainer_name, city, distance, text, specialties[], dog_age, format, flags[], notified_count,
     *     open_url, optout_url, postal.
     */
    public static function trainer_notice( array $d ) {
        $where   = $d['city'] ? 'near ' . $d['city'] : 'near ZIP ' . $d['zip'];
        $subject = 'New dog training request ' . $where;
        $age     = isset( self::AGE_LABELS[ $d['dog_age'] ] ) ? self::AGE_LABELS[ $d['dog_age'] ] : 'Not given';
        $fmt     = self::format_label( $d['format'] );
        $help    = self::specialty_list( $d['specialties'] );
        $miles   = (int) round( $d['distance'] );
        $dist    = $miles <= 1 ? 'about 1 mile from your listing' : 'about ' . $miles . ' miles from your listing';
        if ( ! empty( $d['by_video'] ) ) {
            $dist = 'not a factor. The owner is open to video sessions and your listing offers them';
        }

        $extra_lines = array();
        if ( in_array( 'aggression', $d['flags'], true ) ) {
            $extra_lines[] = 'The owner mentioned a bite or aggression. You are getting this because you hold a behavior credential.';
        }
        if ( in_array( 'medical', $d['flags'], true ) ) {
            $extra_lines[] = 'The owner mentioned a health problem. We suggested seeing a vet.';
        }
        $sent_to = (int) $d['notified_count'] <= 1 ? 'You are the only trainer from our directory who got this request.' : (int) $d['notified_count'] . ' trainers got this request.';

        $text  = 'Hi ' . $d['trainer_name'] . ",\n\n";
        $text .= 'A dog owner ' . $where . " asked for dog training help.\n\n";
        $text .= "What they wrote:\n\"" . $d['text'] . "\"\n\n";
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
        $text .= "\nOpen the request to see how to reach the owner:\n" . $d['open_url'] . "\n\n";
        $text .= $sent_to . " There is no charge for this request.\n\n";
        $text .= "Do not want requests like this? Stop them here:\n" . $d['optout_url'] . "\n\n";
        $text .= '--' . "\nYou got this because your business is listed on Goody Doggy and a dog owner asked for a trainer.\n" . $d['postal'] . "\n";

        $body  = '<p style="margin:0 0 12px 0;">Hi ' . self::h( $d['trainer_name'] ) . ',</p>';
        $body .= '<p style="margin:0 0 12px 0;">A dog owner ' . self::h( $where ) . ' asked for dog training help.</p>';
        $body .= '<p style="margin:0;font-weight:bold;">What they wrote</p>' . self::quote_html( $d['text'] );
        $body .= '<p style="margin:12px 0 0 0;">';
        if ( $help ) {
            $body .= '<strong>Help with:</strong> ' . self::h( $help ) . '<br>';
        }
        $body .= '<strong>Dog age:</strong> ' . self::h( $age ) . '<br><strong>Training they want:</strong> ' . self::h( $fmt ) . ( empty( $d['unplaced'] ) ? '<br><strong>Distance:</strong> ' . self::h( $dist ) : '' ) . '</p>';
        foreach ( $extra_lines as $l ) {
            $body .= '<p style="margin:12px 0 0 0;">' . self::h( $l ) . '</p>';
        }
        $body .= self::button( $d['open_url'], 'Read and reply' );
        $body .= '<p style="margin:0;">' . self::h( $sent_to ) . ' There is no charge for this request.</p>';

        $footer = 'You got this because your business is listed on Goody Doggy and a dog owner asked for a trainer. '
            . '<a href="' . self::h( $d['optout_url'] ) . '" style="color:#5a6a7b;">Stop requests like this</a>.<br>' . self::h( $d['postal'] );

        return array( 'subject' => $subject, 'text' => $text, 'html' => self::wrap( 'New dog training request', $body, $footer ) );
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
