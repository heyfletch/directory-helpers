<?php
/**
 * Owner Requests: the token pages.
 *
 *   /gd-request/trainer/<token>/  the trainer's request page (shows the owner's contact once opened)
 *   /gd-request/optout/<token>/   stop requests like this (also the one-click List-Unsubscribe target)
 *   /gd-request/confirm/<token>/  the owner confirms a held request
 *   /gd-request/delete/<token>/   the owner deletes their request
 *
 * Every page is noindex and uncached. A GET only shows a page; the actions that
 * change something are POSTs, so a mail scanner that opens a link changes
 * nothing (the trainer page records "viewed", which the plan accepts).
 * While preview_only is on, the pages answer only for admins.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Pages {

    const BASE = 'gd-request';

    public static function url( $action, $token ) {
        return home_url( '/' . self::BASE . '/' . $action . '/' . $token . '/' );
    }

    public function __construct() {
        add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
    }

    public function maybe_render() {
        $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
        if ( ! preg_match( '#^/' . self::BASE . '/(trainer|optout|confirm|delete)/([a-f0-9]{32})/?$#', $path, $m ) ) {
            return;
        }
        if ( ! DH_OR_Settings::visitor_allowed() ) {
            return; // WordPress answers 404.
        }
        $action = $m[1];
        $token  = $m[2];

        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow' );
        do_action( 'litespeed_control_set_nocache', 'owner-requests token page' );
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        $is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
        $handler = 'page_' . $action;
        status_header( 200 );
        echo $this->$handler( $token, $is_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        exit;
    }

    /* ---------- trainer ---------- */

    private function page_trainer( $token, $is_post ) {
        $route = DH_OR_Store::route_by_token( $token );
        if ( ! $route ) {
            return $this->shell( 'Request not found', '<p>This link is not valid. It may have been removed.</p>' );
        }
        $request = DH_OR_Store::get_request( $route->request_id );
        if ( ! $request || ! $request->owner_email ) {
            return $this->shell( 'Request closed', '<p>This request is no longer available. The dog owner withdrew it or deleted it.</p>' );
        }
        if ( in_array( $request->status, array( 'withdrawn', 'deleted', 'expired' ), true ) ) {
            return $this->shell( 'Request closed', '<p>This request is no longer available. The dog owner withdrew it or deleted it.</p>' );
        }

        $notice = '';
        if ( $is_post ) {
            $do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
            if ( DH_OR_Flow::route_outcome( $route, $do ) ) {
                $labels = array( 'replied' => 'Marked as replied.', 'not_fit' => 'Marked as not a fit.', 'booked' => 'Marked as already booked.' );
                $notice = '<p class="ok">' . esc_html( $labels[ $do ] ) . ' Thank you.</p>';
                $route  = DH_OR_Store::route_by_token( $token );
            }
        }
        DH_OR_Flow::route_viewed( $route );

        $city   = DH_OR_Candidates::nearest_city_name( (float) $request->lat, (float) $request->lng );
        $where  = $city ? 'near ' . $city : 'near ZIP ' . $request->zip;
        $specs  = DH_OR_Messages::specialty_list( $request->specialties ? explode( ',', $request->specialties ) : array() );
        $age    = isset( DH_OR_Messages::AGE_LABELS[ $request->dog_age ] ) ? DH_OR_Messages::AGE_LABELS[ $request->dog_age ] : 'Not given';
        $fmt    = isset( DH_OR_Messages::FORMAT_LABELS[ $request->format ] ) ? DH_OR_Messages::FORMAT_LABELS[ $request->format ] : 'Any kind of training';
        $flags  = $request->flags ? explode( ',', $request->flags ) : array();

        $body  = $notice;
        $body .= '<p>A dog owner ' . esc_html( $where ) . ' asked for dog training help.</p>';
        $body .= '<blockquote>' . nl2br( esc_html( $request->problem_text ) ) . '</blockquote>';
        $body .= '<ul class="facts">';
        if ( $specs ) {
            $body .= '<li><strong>Help with:</strong> ' . esc_html( $specs ) . '</li>';
        }
        $body .= '<li><strong>Dog age:</strong> ' . esc_html( $age ) . '</li><li><strong>Training they want:</strong> ' . esc_html( $fmt ) . '</li></ul>';
        if ( in_array( 'aggression', $flags, true ) ) {
            $body .= '<p>The owner mentioned a bite or aggression. You are seeing this because you hold a behavior credential.</p>';
        }
        if ( in_array( 'medical', $flags, true ) ) {
            $body .= '<p>The owner mentioned a health problem. We suggested seeing a vet.</p>';
        }

        $body .= '<h2>Reach the owner</h2><div class="contact"><p><strong>' . esc_html( $request->owner_first_name ) . '</strong></p>'
            . '<p>Email: <a href="mailto:' . esc_attr( $request->owner_email ) . '">' . esc_html( $request->owner_email ) . '</a></p>'
            . ( $request->owner_phone ? '<p>Phone: ' . esc_html( $request->owner_phone ) . '</p>' : '' ) . '</div>';

        $body .= '<h2>After you reply</h2><form method="post" class="actions">'
            . '<button name="do" value="replied">I replied</button> <button name="do" value="not_fit" class="alt">Not a fit</button> <button name="do" value="booked" class="alt">Already booked</button></form>';
        if ( ! empty( $route->outcome ) ) {
            $body .= '<p class="small">Last answer: ' . esc_html( str_replace( '_', ' ', $route->outcome ) ) . '.</p>';
        }
        $body .= '<p class="small"><a href="' . esc_url( self::url( 'optout', $token ) ) . '">Stop requests like this</a></p>';
        return $this->shell( 'Dog training request', $body );
    }

    /* ---------- trainer opt-out ---------- */

    private function page_optout( $token, $is_post ) {
        $route = DH_OR_Store::route_by_token( $token );
        if ( ! $route ) {
            return $this->shell( 'Link not found', '<p>This link is not valid.</p>' );
        }
        // RFC 8058 one-click: a POST with List-Unsubscribe=One-Click acts at once. A plain POST is the page button.
        if ( $is_post ) {
            DH_OR_Flow::route_optout( $route );
            return $this->shell( 'Request stopped', '<p>Done. We will not send you dog owner requests again.</p>' );
        }
        return $this->shell( 'Stop requests like this', '<p>Press the button and we will not send you dog owner requests again.</p><form method="post"><button>Stop requests</button></form>' );
    }

    /* ---------- owner confirm ---------- */

    private function page_confirm( $token, $is_post ) {
        $request = DH_OR_Store::request_by_owner_token( $token );
        if ( ! $request ) {
            return $this->shell( 'Link not found', '<p>This link is not valid, or the request was deleted.</p>' );
        }
        if ( 'held' !== $request->status ) {
            $note = in_array( $request->status, array( 'routed', 'thin' ), true ) ? 'Your request was already sent.' : 'This request cannot be sent.';
            return $this->shell( 'Request status', '<p>' . esc_html( $note ) . '</p>' );
        }
        if ( ! $is_post ) {
            return $this->shell( 'Send your request', '<p>You asked trainers near ' . esc_html( $request->zip ) . ' for help. We have not sent it yet.</p><form method="post"><button>Send my request</button></form>' );
        }
        DH_OR_Flow::$result_html = '';
        $done = DH_OR_Flow::confirm( $token );
        return $this->shell( 'Request sent', $done ? DH_OR_Flow::$result_html : '<p>This request cannot be sent.</p>' );
    }

    /* ---------- owner delete ---------- */

    private function page_delete( $token, $is_post ) {
        $request = DH_OR_Store::request_by_owner_token( $token );
        if ( ! $request ) {
            return $this->shell( 'Request deleted', '<p>This request is already deleted, or the link is not valid.</p>' );
        }
        if ( ! $is_post ) {
            return $this->shell( 'Delete your request', '<p>This removes your name, email, phone, and what you wrote. Trainers who already got it keep what they were sent.</p><form method="post"><button>Delete my request</button></form>' );
        }
        DH_OR_Flow::delete_by_owner( $token );
        return $this->shell( 'Request deleted', '<p>Your request is deleted.</p>' );
    }

    /* ---------- shell ---------- */

    private function shell( $title, $body ) {
        $home = esc_url( home_url( '/' ) );
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' - Goody Doggy</title><style>'
            . 'body{margin:0;background:#f4f7fa;color:#1b2b3a;font:16px/1.5 Lexend,Arial,sans-serif}'
            . '.top{background:#0572bd;padding:14px 20px}.top a{color:#fff;font-weight:700;text-decoration:none}'
            . 'main{max-width:600px;margin:24px auto;padding:0 16px}.card{background:#fff;border:2px solid #b38900;border-radius:16px;padding:24px}'
            . 'h1{color:#034c7c;font-size:28px;line-height:1.15;margin:0 0 12px}h2{color:#034c7c;font-size:20px;margin:24px 0 8px}'
            . 'blockquote{margin:12px 0;padding:10px 16px;border-left:4px solid #ffd64f;background:#f4f7fa}'
            . '.facts{padding-left:20px}.contact{background:#ebf7fe;border-radius:10px;padding:4px 16px}'
            . 'button{font:inherit;font-weight:700;background:#0572bd;color:#fff;border:0;border-radius:999px;padding:12px 22px;cursor:pointer}'
            . 'button.alt{background:#fff;color:#0572bd;border:2px solid #0572bd}.small{font-size:14px;color:#5a6a7b}.ok{color:#0b7545;font-weight:700}'
            . '</style></head><body><div class="top"><a href="' . $home . '">Goody Doggy</a></div><main><div class="card"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div></main></body></html>';
    }
}
