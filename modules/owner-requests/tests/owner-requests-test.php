<?php
/**
 * Plain-PHP tests for the Owner Requests module: text cleaning, the Gate B screen,
 * the matcher (fixture requests against a synthetic trainer pool) and the messages.
 *
 * Run: php modules/owner-requests/tests/owner-requests-test.php
 * Exits 0 on pass, 1 on any failure. No WordPress needed.
 */

define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
$inc = dirname( __DIR__ ) . '/includes/';
require_once $inc . 'class-or-text.php';
require_once $inc . 'class-or-screen.php';
require_once $inc . 'class-or-matcher.php';
require_once $inc . 'class-or-messages.php';

// Gate A's disposable check reads a data file; load the class with stubs for the WP calls it never reaches here.
require_once $inc . 'class-or-gates.php';

$failures = 0;
$tests    = 0;

function check( $label, $actual, $expected ) {
    global $failures, $tests;
    $tests++;
    if ( $actual === $expected ) {
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label}\n      expected " . var_export( $expected, true ) . "\n      got      " . var_export( $actual, true ) . "\n";
    }
}
function check_true( $label, $cond ) {
    check( $label, (bool) $cond, true );
}

/* ---------------------------------------------------------------- text */

check( 'email removed', DH_OR_Text::strip_contact( 'write me at jane@example.com ok' ), 'write me at [removed] ok' );
check( 'spelled-out email removed', DH_OR_Text::has_contact( 'jane at gmail dot com' ), true );
check( 'phone (414) 555-0199 removed', DH_OR_Text::strip_contact( 'call (414) 555-0199 now' ), 'call [removed] now' );
check( 'phone 414-555-0199 removed', DH_OR_Text::strip_contact( 'call 414-555-0199 now' ), 'call [removed] now' );
check( 'phone +1 414 555 0199 removed', DH_OR_Text::strip_contact( 'call +1 414 555 0199 now' ), 'call [removed] now' );
check( 'phone 4145550199 removed', DH_OR_Text::strip_contact( 'call 4145550199 now' ), 'call [removed] now' );
check( 'url removed', DH_OR_Text::strip_contact( 'see https://example.com/page?x=1 please' ), 'see [removed] please' );
check( 'www removed', DH_OR_Text::strip_contact( 'see www.example.com please' ), 'see [removed] please' );
check( 'bare domain removed', DH_OR_Text::strip_contact( 'my site is example.com/dogs' ), 'my site is [removed]' );
check( 'dog age text untouched', DH_OR_Text::strip_contact( 'My lab is 2 years old and weighs 70 lbs.' ), 'My lab is 2 years old and weighs 70 lbs.' );
check( 'e.g. untouched', DH_OR_Text::strip_contact( 'He barks, e.g. at the mailman.' ), 'He barks, e.g. at the mailman.' );
check( 'clean_problem strips tags', DH_OR_Text::clean_problem( '<b>My dog</b> pulls' ), 'My dog pulls' );
check( 'clean_problem caps length', mb_strlen( DH_OR_Text::clean_problem( str_repeat( 'a', 2000 ), 100 ) ), 103 );
check( 'zip ok', DH_OR_Text::normalize_zip( ' 53202 ' ), '53202' );
check( 'zip plus four', DH_OR_Text::normalize_zip( '53202-1234' ), '53202' );
check( 'zip too short', DH_OR_Text::normalize_zip( '5320' ), '' );
check( 'zip canadian', DH_OR_Text::normalize_zip( 'K1A 0B1' ), '' );
check( 'email normalised', DH_OR_Text::normalize_email( ' Jane@Example.COM ' ), 'jane@example.com' );
check( 'email invalid', DH_OR_Text::normalize_email( 'jane@' ), '' );
check( 'phone too short', DH_OR_Text::clean_phone( '555-0199' ), '' );
check( 'phone kept', DH_OR_Text::clean_phone( '(414) 555-0199' ), '4145550199' );
check( 'name cleaned', DH_OR_Text::clean_name( "  Jo<script>hn O'Neil " ), "Joscripthn O'Neil" );

/* ---------------------------------------------------------------- gate A pure parts */

check_true( 'mailinator is disposable', DH_OR_Gates::is_disposable( 'mailinator.com' ) );
check_true( 'subdomain of a disposable domain is disposable', DH_OR_Gates::is_disposable( 'x.mailinator.com' ) );
check( 'gmail is not disposable', DH_OR_Gates::is_disposable( 'gmail.com' ), false );
check( 'fletcherdigital.com is not disposable', DH_OR_Gates::is_disposable( 'fletcherdigital.com' ), false );

/* ---------------------------------------------------------------- screen (Gate B) */

$s = DH_OR_Screen::screen( 'My 8 month old puppy bites my hands and chews everything.' );
check( 'puppy request is green', $s['tier'], 'green' );
check( 'puppy age inferred', $s['dog_age'], 'puppy' );
check_true( 'puppy request has a specialty', in_array( 'puppy', $s['specialties'], true ) );

$s = DH_OR_Screen::screen( 'Our dog pulls on the leash and barks at other dogs on walks.' );
check( 'leash request is green', $s['tier'], 'green' );
check_true( 'leash specialty found', in_array( 'leash', $s['specialties'], true ) );
check_true( 'barking specialty found', in_array( 'barking', $s['specialties'], true ) );

$s = DH_OR_Screen::screen( 'Best SEO services and backlinks for your dog trainer website, click here for a free trial.' );
check( 'advertisement is red', $s['tier'], 'red' );
check_true( 'advertisement reason logged', in_array( 'advertisement', $s['reasons'], true ) );

$s = DH_OR_Screen::screen( 'I will find you and kill the trainer if she does not call.' );
check( 'threat is red', $s['tier'], 'red' );

$s = DH_OR_Screen::screen( 'He is not listening to me at all and I am done.' );
check( 'no dog signal is amber', $s['tier'], 'amber' );

$s = DH_OR_Screen::screen( 'He is not listening to me at all and I am done.', array(), 'adult' );
check( 'dog age pick counts as a dog signal', $s['tier'], 'green' );

$s = DH_OR_Screen::screen( 'My dog bit my neighbor yesterday and drew blood.' );
check_true( 'bite flags aggression', in_array( 'aggression', $s['flags'], true ) );
check( 'bite request still green (routes to credentialed only)', $s['tier'], 'green' );

$s = DH_OR_Screen::screen( 'My dog is aggressive toward other dogs when we walk.' );
check_true( 'aggressive flags aggression', in_array( 'aggression', $s['flags'], true ) );

$s = DH_OR_Screen::screen( 'My dog threw up twice and the vet says it might be an infection, and he also barks a lot.' );
check_true( 'vet and sick words flag medical', in_array( 'medical', $s['flags'], true ) );

$s = DH_OR_Screen::screen( 'My puppy mouths my hands and nips my kids.' );
check( 'nipping puppy does not flag aggression', in_array( 'aggression', $s['flags'], true ), false );

$s = DH_OR_Screen::screen( 'Please help with my dog.', array( 'recall', 'leash' ) );
check( 'owner chips come first', $s['primary'], 'recall' );

check( 'age from text 5 weeks', DH_OR_Screen::dog_age_group( 'she is 5 weeks old' ), 'puppy' );
check( 'age from text 14 months', DH_OR_Screen::dog_age_group( 'he is 14 months old' ), 'adolescent' );
check( 'age from text 4 years', DH_OR_Screen::dog_age_group( 'he is 4 years old' ), 'adult' );
check( 'age from text 11 years', DH_OR_Screen::dog_age_group( 'he is 11 years old' ), 'senior' );
check( 'owner age pick wins over text', DH_OR_Screen::dog_age_group( 'my puppy', 'adult' ), 'adult' );

/* ---------------------------------------------------------------- matcher */

// Milwaukee ZIP 53202.
$MKE = array( 'lat' => 43.04967, 'lng' => -87.89238 );

function trainer( $id, $dlat, $dlng, $extra = array() ) {
    global $MKE;
    return array_merge( array(
        'id' => $id, 'name' => "Trainer {$id}", 'url' => '', 'city' => 'Test City',
        'lat' => $MKE['lat'] + $dlat, 'lng' => $MKE['lng'] + $dlng,
        'rating' => 4.5, 'reviews' => 20,
        'specialties' => array( 'leash', 'barking', 'basic', 'puppy', 'reactivity' ),
        'ages' => array( 'puppy', 'adolescent', 'adult', 'senior' ),
        'services' => array( 'private', 'home', 'group' ),
        'certs' => array(), 'lane' => 'A',
    ), $extra );
}
function req( $extra = array() ) {
    global $MKE;
    return array_merge( array( 'lat' => $MKE['lat'], 'lng' => $MKE['lng'], 'specialties' => array(), 'dog_age' => '', 'format' => 'any', 'flags' => array(), 'viewed_id' => 0 ), $extra );
}
function opts( $extra = array() ) {
    return array_merge( array( 'lanes' => array( 'A' ), 'min' => 3, 'max' => 5, 'radii' => array( 15, 25, 50 ), 'weekly_cap' => 3, 'recent' => array(), 'suppressed' => array(), 'behavior_certs' => array( 'CBCC-KA', 'IAABC-CDBC' ) ), $extra );
}
function ids( $res ) {
    return array_map( function ( $r ) { return $r['profile_id']; }, $res['routes'] );
}

// 1. Dense city: 12 trainers within about 5 miles.
$dense = array();
for ( $i = 1; $i <= 12; $i++ ) {
    $dense[] = trainer( $i, 0.01 * $i, 0.01 * $i, array( 'rating' => 4.0 + $i * 0.05, 'reviews' => 10 + $i ) );
}
$r = DH_OR_Matcher::match( $dense, req(), opts() );
check( 'dense city: never more than five', count( $r['routes'] ), 5 );
check( 'dense city: radius used is 15', $r['radius_used'], 15 );
check( 'dense city: not thin', $r['thin_market'], false );
check( 'dense city: best rank first', $r['routes'][0]['profile_id'], 12 );
check_true( 'dense city: every route within the radius used', array_reduce( $r['routes'], function ( $c, $x ) use ( $r ) { return $c && $x['distance'] <= $r['radius_used']; }, true ) );

// 2. Small town: 2 trainers at 15 miles, 2 more at 20 miles -> widens to 25 and takes 4.
$town = array(
    trainer( 1, 0.05, 0.0 ), trainer( 2, 0.0, 0.05 ),
    trainer( 3, 0.29, 0.0, array( 'rating' => 4.9 ) ), trainer( 4, 0.0, 0.38, array( 'rating' => 4.8 ) ),
);
$r = DH_OR_Matcher::match( $town, req(), opts() );
check( 'small town: widens to 25 miles', $r['radius_used'], 25 );
check( 'small town: four recipients', count( $r['routes'] ), 4 );

// 3. No trainer within 50 miles.
$far = array( trainer( 1, 1.5, 0.0 ), trainer( 2, 0.0, 2.0 ) );
$r = DH_OR_Matcher::match( $far, req(), opts() );
check( 'no trainer within 50 miles: none routed', count( $r['routes'] ), 0 );
check( 'no trainer within 50 miles: thin', $r['thin_market'], true );
check( 'no trainer within 50 miles: radius used 50', $r['radius_used'], 50 );

// 4. One trainer close: sent to the one, flagged thin.
$r = DH_OR_Matcher::match( array( trainer( 1, 0.02, 0.0 ) ), req(), opts() );
check( 'one trainer: routed to the one', ids( $r ), array( 1 ) );
check( 'one trainer: thin', $r['thin_market'], true );

// 5. Puppy request: trainers that list only adult dogs are skipped; trainers listing no ages stay.
$pool = array(
    trainer( 1, 0.01, 0.0, array( 'ages' => array( 'adult' ) ) ),
    trainer( 2, 0.02, 0.0, array( 'ages' => array( 'puppy', 'adult' ) ) ),
    trainer( 3, 0.03, 0.0, array( 'ages' => array() ) ),
);
$r = DH_OR_Matcher::match( $pool, req( array( 'dog_age' => 'puppy' ) ), opts() );
check( 'puppy request skips adult-only trainers', ids( $r ), array( 2, 3 ) );
$r = DH_OR_Matcher::match( $pool, req( array( 'dog_age' => 'adult' ) ), opts() );
check( 'adult request keeps all three', count( $r['routes'] ), 3 );

// 6. Specialty overlap required when the owner named one; trainers with no specialties listed do not match.
$pool = array(
    trainer( 1, 0.01, 0.0, array( 'specialties' => array( 'potty' ) ) ),
    trainer( 2, 0.02, 0.0, array( 'specialties' => array( 'leash' ) ) ),
    trainer( 3, 0.03, 0.0, array( 'specialties' => array() ) ),
);
$r = DH_OR_Matcher::match( $pool, req( array( 'specialties' => array( 'leash' ) ) ), opts() );
check( 'specialty overlap required', ids( $r ), array( 2 ) );
$r = DH_OR_Matcher::match( $pool, req(), opts() );
check( 'no specialty named keeps everyone', count( $r['routes'] ), 3 );

// 7. Bite request: only credentialed trainers.
$pool = array(
    trainer( 1, 0.01, 0.0, array( 'certs' => array( 'CPDT-KA' ) ) ),
    trainer( 2, 0.02, 0.0, array( 'certs' => array( 'CBCC-KA' ) ) ),
    trainer( 3, 0.03, 0.0, array( 'certs' => array( 'IAABC-CDBC', 'CPDT-KA' ) ) ),
    trainer( 4, 0.04, 0.0 ),
);
$r = DH_OR_Matcher::match( $pool, req( array( 'flags' => array( 'aggression' ) ) ), opts() );
$got = ids( $r );
sort( $got );
check( 'bite request reaches only behavior-credentialed trainers', $got, array( 2, 3 ) );
$r = DH_OR_Matcher::match( $pool, req( array( 'flags' => array( 'medical' ) ) ), opts() );
$got = ids( $r );
sort( $got );
check( 'medical request reaches only behavior-credentialed trainers', $got, array( 2, 3 ) );

// 8. Never a suppressed, store-chain, capped or lane-off trainer.
$pool = array(
    trainer( 1, 0.01, 0.0 ),
    trainer( 2, 0.02, 0.0 ),
    trainer( 3, 0.03, 0.0, array( 'name' => 'PetSmart Training' ) ),
    trainer( 4, 0.04, 0.0 ),
    trainer( 5, 0.05, 0.0, array( 'lane' => 'B' ) ),
    trainer( 6, 0.06, 0.0, array( 'lane' => 'C' ) ),
    trainer( 7, 0.07, 0.0 ),
);
$r = DH_OR_Matcher::match( $pool, req(), opts( array( 'suppressed' => array( 1 ), 'recent' => array( 2 => 3 ) ) ) );
$got = ids( $r );
sort( $got );
check( 'skips suppressed, chain, capped and closed-lane trainers', $got, array( 4, 7 ) );
check_true( 'skip reasons recorded', isset( $r['skipped_reasons']['weekly_cap'] ) && isset( $r['skipped_reasons']['suppressed'] ) && isset( $r['skipped_reasons']['store_chain'] ) && isset( $r['skipped_reasons']['lane_off'] ) );
$r = DH_OR_Matcher::match( $pool, req(), opts( array( 'lanes' => array( 'A', 'B', 'C' ), 'suppressed' => array( 1 ), 'recent' => array( 2 => 3 ) ) ) );
check_true( 'with all lanes on, lane B and C trainers appear', count( array_intersect( ids( $r ), array( 5, 6 ) ) ) === 2 );
check( 'one request below the cap is fine', count( DH_OR_Matcher::match( $pool, req(), opts( array( 'recent' => array( 2 => 2 ) ) ) )['routes'] ) >= 1, true );

// 9. Featured has no effect: the matcher has no input for it, and extra keys change nothing.
$a = array( trainer( 1, 0.01, 0.0, array( 'featured' => 500 ) ), trainer( 2, 0.01, 0.0 ) );
$b = array( trainer( 1, 0.01, 0.0 ), trainer( 2, 0.01, 0.0, array( 'featured' => 500 ) ) );
check( 'featured flag does not change order', ids( DH_OR_Matcher::match( $a, req(), opts() ) ), ids( DH_OR_Matcher::match( $b, req(), opts() ) ) );

// 9b. Reserved spot: an owner open to video gets the best-fitting Featured video trainer, wherever they are.
$full = array();
for ( $i = 1; $i <= 6; $i++ ) {
    $full[] = trainer( $i, 0.01 * $i, 0.0, array( 'rating' => 5.0, 'reviews' => 100 ) );
}
$fv = trainer( 50, 10.0, 0.0, array( 'rating' => 3.5, 'reviews' => 3, 'services' => array( 'video' ), 'featured' => true ) );
$r  = DH_OR_Matcher::match( array_merge( $full, array( $fv ) ), req(), opts() );
check( 'reserved spot: Featured video trainer is in a full list', in_array( 50, ids( $r ), true ), true );
check( 'reserved spot: list stays at five', count( $r['routes'] ), 5 );
check( 'reserved spot: Featured video trainer takes the last spot', end( $r['routes'] )['profile_id'], 50 );
$r = DH_OR_Matcher::match( array_merge( $full, array( $fv ) ), req( array( 'format' => 'home' ) ), opts() );
check( 'reserved spot: not for owners who want in-home training', in_array( 50, ids( $r ), true ), false );
$r = DH_OR_Matcher::match( array_merge( $full, array( $fv ) ), req( array( 'specialties' => array( 'agility' ) ) ), opts() );
check( 'reserved spot: only when the trainer fits the dog problem', in_array( 50, ids( $r ), true ), false );
$nf = trainer( 51, 10.0, 0.0, array( 'services' => array( 'video' ) ) );
$r  = DH_OR_Matcher::match( array_merge( $full, array( $nf ) ), req(), opts() );
check( 'reserved spot: never for a trainer who is not Featured', in_array( 51, ids( $r ), true ), false );

// 10. Order is rank score, then distance.
$pool = array(
    trainer( 1, 0.05, 0.0, array( 'rating' => 4.0, 'reviews' => 5 ) ),
    trainer( 2, 0.01, 0.0, array( 'rating' => 5.0, 'reviews' => 200 ) ),
    trainer( 3, 0.02, 0.0, array( 'rating' => 4.5, 'reviews' => 30 ) ),
    trainer( 4, 0.03, 0.0, array( 'rating' => 4.5, 'reviews' => 30 ) ),
);
check( 'order is score then distance', ids( DH_OR_Matcher::match( $pool, req(), opts() ) ), array( 2, 3, 4, 1 ) );
check( 'rank score matches the site formula', round( DH_OR_Matcher::rank_score( 4.9, 100 ), 4 ), round( 4.9 * 0.9 + ( log10( 101 ) / 2 ) * 5 * 0.1, 4 ) );
check( 'unrated trainer scores zero', DH_OR_Matcher::rank_score( 0, 0 ), 0.0 );

// 11. Video only: far video trainers count at any distance, others do not.
$pool = array(
    trainer( 1, 0.01, 0.0, array( 'services' => array( 'home' ) ) ),
    trainer( 2, 6.0, 0.0, array( 'services' => array( 'video' ) ) ),
    trainer( 3, 0.02, 0.0, array( 'services' => array( 'video', 'home' ) ) ),
    trainer( 4, -7.0, 3.0, array( 'services' => array( 'video' ) ) ),
);
$r = DH_OR_Matcher::match( $pool, req( array( 'format' => 'video' ) ), opts() );
$got = ids( $r );
sort( $got );
check( 'video-only owner reaches video trainers at any distance', $got, array( 2, 3, 4 ) );
$r = DH_OR_Matcher::match( $pool, req( array( 'format' => 'home' ) ), opts() );
check( 'in-home owner reaches only in-home trainers nearby', ids( $r ), array( 1, 3 ) );

// 12. Format "any" with a short local pool is topped up with video trainers at any distance.
$pool = array(
    trainer( 1, 0.01, 0.0 ),
    trainer( 2, 6.0, 0.0, array( 'services' => array( 'video' ) ) ),
    trainer( 3, 0.0, 9.0, array( 'services' => array( 'private' ) ) ),
);
$r = DH_OR_Matcher::match( $pool, req( array( 'format' => 'any' ) ), opts() );
$got = ids( $r );
sort( $got );
check( 'format any tops up with far video trainers only', $got, array( 1, 2 ) );
$flags = array();
foreach ( $r['routes'] as $row ) {
    $flags[ $row['profile_id'] ] = $row['by_video'];
}
check( 'only the far video trainer is marked by_video', $flags, array( 1 => 0, 2 => 1 ) );

// 13. Profile-page request: the viewed trainer goes first even when far away or off-specialty; not when ineligible.
$pool = array(
    trainer( 1, 0.01, 0.0 ), trainer( 2, 0.02, 0.0 ), trainer( 3, 0.03, 0.0 ),
    trainer( 9, 3.0, 0.0, array( 'specialties' => array( 'potty' ), 'rating' => 3.0, 'reviews' => 2 ) ),
);
$r = DH_OR_Matcher::match( $pool, req( array( 'viewed_id' => 9, 'specialties' => array( 'leash' ) ) ), opts() );
check( 'viewed trainer is first', $r['routes'][0]['profile_id'], 9 );
check( 'viewed trainer is marked', $r['routes'][0]['is_viewed'], 1 );
check( 'viewed trainer plus the best others', ids( $r ), array( 9, 1, 2, 3 ) );
$r = DH_OR_Matcher::match( $pool, req( array( 'viewed_id' => 9 ) ), opts( array( 'suppressed' => array( 9 ) ) ) );
check_true( 'suppressed viewed trainer is not included', ! in_array( 9, ids( $r ), true ) );
$r = DH_OR_Matcher::match( $pool, req( array( 'viewed_id' => 9, 'flags' => array( 'aggression' ) ) ), opts() );
check_true( 'viewed trainer without a behavior credential is not included for a bite request', ! in_array( 9, ids( $r ), true ) );
$r = DH_OR_Matcher::match( $pool, req( array( 'viewed_id' => 9 ) ), opts( array( 'lanes' => array( 'B' ) ) ) );
check( 'viewed trainer in a closed lane is not included', count( $r['routes'] ), 0 );

// 14. Total never above five with a viewed trainer.
$dense2 = $dense;
$dense2[] = trainer( 99, 5.0, 0.0 );
$r = DH_OR_Matcher::match( $dense2, req( array( 'viewed_id' => 99 ) ), opts() );
check( 'viewed trainer plus others still at most five', count( $r['routes'] ), 5 );

// 15. Rank positions are 1..n.
$r = DH_OR_Matcher::match( $dense, req(), opts() );
check( 'rank positions count up', array_map( function ( $x ) { return $x['rank']; }, $r['routes'] ), array( 1, 2, 3, 4, 5 ) );

// 16. Weekly cap and counts: a trainer at the cap is skipped, a trainer at cap minus one is not.
$pool = array( trainer( 1, 0.01, 0.0 ), trainer( 2, 0.02, 0.0 ), trainer( 3, 0.03, 0.0 ) );
$r = DH_OR_Matcher::match( $pool, req(), opts( array( 'recent' => array( 1 => 3, 2 => 2 ) ) ) );
check( 'trainer at the weekly cap is skipped', ids( $r ), array( 2, 3 ) );

/* ---------------------------------------------------------------- messages */

$trainer_msg = DH_OR_Messages::trainer_notice( array(
    'trainer_name' => 'Benson K-9 Training', 'city' => 'Freeport, IL', 'zip' => '61032', 'distance' => 7.4,
    'text' => 'My dog is 1 year old and pulls on the leash.', 'specialties' => array( 'leash' ), 'dog_age' => 'adolescent',
    'format' => 'home', 'flags' => array( 'aggression' ), 'notified_count' => 3,
    'open_url' => 'https://example.test/gd-request/trainer/abc/', 'optout_url' => 'https://example.test/gd-request/optout/abc/',
    'postal' => '[postal address not set]',
) );
check( 'trainer subject is accurate', $trainer_msg['subject'], 'New dog training request near Freeport, IL' );
check_true( 'trainer text has the open link', false !== strpos( $trainer_msg['text'], 'https://example.test/gd-request/trainer/abc/' ) );
check_true( 'trainer html has the Read and reply button', false !== strpos( $trainer_msg['html'], 'Read and reply' ) );
check_true( 'trainer text has the opt-out link', false !== strpos( $trainer_msg['text'], '/gd-request/optout/abc/' ) );
check_true( 'trainer text has the postal line', false !== strpos( $trainer_msg['text'], '[postal address not set]' ) );
check_true( 'trainer text says how many trainers got it', false !== strpos( $trainer_msg['text'], '3 trainers got this request' ) );
check_true( 'trainer text notes the credential reason', false !== strpos( $trainer_msg['text'], 'behavior credential' ) );
check( 'trainer message carries no email address', (bool) preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[a-z]{2,}/', $trainer_msg['text'] . $trainer_msg['html'] ), false );
check( 'trainer message has no Featured pitch', (bool) preg_match( '/featured/i', $trainer_msg['text'] . $trainer_msg['html'] ), false );

$thin_msg = DH_OR_Messages::owner_receipt( array( 'first_name' => 'Jo', 'zip' => '53202', 'text' => 'x', 'trainers' => array(), 'flags' => array( 'medical' ), 'delete_url' => 'https://example.test/d/', 'fallback_url' => 'https://example.test/city/', 'postal' => 'P' ) );
check( 'thin-market receipt subject', $thin_msg['subject'], 'We could not send your dog training request yet' );
check_true( 'thin-market receipt links to the city page', false !== strpos( $thin_msg['text'], 'https://example.test/city/' ) );
check_true( 'medical receipt has the see-your-vet line', false !== strpos( $thin_msg['text'], 'call your vet first' ) );

$ok_msg = DH_OR_Messages::owner_receipt( array( 'first_name' => 'Jo', 'zip' => '53202', 'text' => 'x', 'trainers' => array( array( 'name' => 'A', 'city' => 'B', 'url' => 'https://example.test/p/a/' ) ), 'flags' => array(), 'delete_url' => 'https://example.test/d/', 'fallback_url' => '', 'postal' => 'P' ) );
check( 'owner receipt subject', $ok_msg['subject'], 'We sent your dog training request' );
check_true( 'owner receipt lists the trainer link', false !== strpos( $ok_msg['text'], 'https://example.test/p/a/' ) );
check_true( 'owner receipt has the delete link', false !== strpos( $ok_msg['text'], 'https://example.test/d/' ) );

$video_msg = DH_OR_Messages::trainer_notice( array(
    'trainer_name' => 'Far Away Training', 'city' => 'Milwaukee', 'zip' => '53202', 'distance' => 577.4, 'by_video' => true,
    'text' => 'My dog pulls.', 'specialties' => array( 'leash' ), 'dog_age' => 'adult', 'format' => 'any', 'flags' => array(),
    'notified_count' => 5, 'open_url' => 'https://example.test/t/', 'optout_url' => 'https://example.test/o/', 'postal' => 'P',
) );
check_true( 'video notice does not claim a distance', false === strpos( $video_msg['text'], 'miles from your listing' ) );
check_true( 'video notice says why the trainer got it', false !== strpos( $video_msg['text'], 'open to video sessions and your listing offers them' ) );
$unplaced_msg = DH_OR_Messages::trainer_notice( array(
    'trainer_name' => 'No Pin Training', 'city' => 'Milwaukee', 'zip' => '53202', 'distance' => 0, 'unplaced' => true,
    'text' => 'My dog pulls.', 'specialties' => array(), 'dog_age' => 'adult', 'format' => 'home', 'flags' => array(),
    'notified_count' => 1, 'open_url' => 'https://example.test/t/', 'optout_url' => 'https://example.test/o/', 'postal' => 'P',
) );
check_true( 'unplaced trainer gets no distance line', false === strpos( $unplaced_msg['text'] . $unplaced_msg['html'], 'Distance' ) );
check_true( 'notice footer does not say near you', false === strpos( $video_msg['text'] . $video_msg['html'], 'owner near you' ) );

check( 'intro: all near', DH_OR_Messages::list_intro( array( array(), array() ), '53202' ), '2 trainers near 53202:' );
check( 'intro: all video', DH_OR_Messages::list_intro( array( array( 'by_video' => 1 ), array( 'by_video' => 1 ) ), '53202' ), '2 trainers who offer video sessions:' );
check( 'intro: one video', DH_OR_Messages::list_intro( array( array( 'by_video' => 1 ) ), '53202' ), '1 trainer who offers video sessions:' );
check( 'intro: far viewed trainer is not called near', DH_OR_Messages::list_intro( array( array( 'far' => 1 ) ), '53202' ), '1 trainer:' );
check( 'tag: far viewed trainer', DH_OR_Messages::item_tag( array( 'far' => 1 ) ), ' - the trainer whose page you were on' );
check( 'intro: mixed is a plain count', DH_OR_Messages::list_intro( array( array(), array( 'by_video' => 1 ) ), '53202' ), '2 trainers:' );
$vid_receipt = DH_OR_Messages::owner_receipt( array( 'first_name' => 'Jo', 'zip' => '53202', 'text' => 'x', 'trainers' => array( array( 'name' => 'Robin\'s K9', 'city' => 'Gainesville, Florida', 'url' => 'https://example.test/p/r/', 'by_video' => true ) ), 'flags' => array(), 'delete_url' => 'https://example.test/d/', 'fallback_url' => '', 'postal' => 'P' ) );
check_true( 'receipt for a video match does not say near', false === strpos( $vid_receipt['text'], 'near 53202' ) );
check_true( 'receipt marks the video match', false !== strpos( $vid_receipt['text'], ' - video sessions: https://example.test/p/r/' ) );
check_true( 'receipt keeps the apostrophe as a plain character', false !== strpos( $vid_receipt['text'], "Robin's K9" ) );

$all = $video_msg['text'] . $video_msg['html'] . $vid_receipt['text'] . $trainer_msg['text'] . $trainer_msg['html'] . $thin_msg['text'] . $ok_msg['text'] . DH_OR_Messages::owner_confirm( array( 'first_name' => 'Jo', 'zip' => '1', 'confirm_url' => 'u', 'delete_url' => 'u', 'postal' => 'p' ) )['text'];
check( 'no em dashes in any template', false !== strpos( $all, "\xE2\x80\x94" ), false );

echo "\n{$tests} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
