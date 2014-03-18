<?php
/*
Plugin Name: KB Plugin
Plugin URI: http://kb20.se/
Description: Kulturbanken Lokalbokning
Version: 1.0
License: MIT
*/

global $kbcal_db_version;
$kbcal_db_version = "1.1";

global $wpdb;
$kbcal_table_name = $wpdb->prefix."kbcalendar";

/* Max bokningstid i minuter */
const MAX_DURATION = 240;

const FORMAT_MYSQL = "Y-m-d H:i:s";
const FORMAT_KBCAL = "Y-m-d H:i";

function kbcal_install()
{
        global $wpdb, $kbcal_db_version, $kbcal_table_name;

        $installed_ver = get_option("kbcal_db_version");

        if ($installed_ver != $kbcal_db_version) {
                $sql = "CREATE TABLE ".$kbcal_table_name." (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			booked_from DATETIME NOT NULL,
			booked_to DATETIME NOT NULL,
			booked_at DATETIME NOT NULL,
			confirmed_at DATETIME DEFAULT NULL,
			localid BIGINT(20) NOT NULL,
			uid BIGINT(20) NOT NULL,
			comment TEXT DEFAULT '',
			PRIMARY KEY  id (id)
			);";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);

                update_option("kbcal_db_version", "$kbcal_db_version");

                $testdata = array(
                        array('2011-10-24 14:00:00', '2011-10-24 16:30:00'),
                        array('2011-10-24 16:30:00', '2011-10-24 17:30:00'),
                        array('2011-10-25 22:30:00', '2011-10-26 01:00:00'),
                        array('2011-10-25 09:00:00', '2011-10-25 12:30:00'),
                        array('2011-10-28 22:30:00', '2011-10-30 01:00:00'),
                        );

                foreach ($testdata as $bookdata) {
                        $data = array('booked_from' => $bookdata[0],
                                      'booked_to'   => $bookdata[1],
                                      'booked_at'   => current_time('mysql'),
                                      'localid'     => 1,
                                      'uid'         => 1,
                                      'comment'     => 'Exempelbokning');
                        $wpdb->insert($kbcal_table_name, $data);
                }

        }
}
register_activation_hook(__FILE__, 'kbcal_install');

/*
  Denna hook är för Wordpress > 3.1 som inte anropar
  register_activation_hook vid uppgradering av plugin.
 */
function kbcal_update_db_check() {
        global $kbcal_db_version;

        if (get_site_option('kbcal_db_version') != $kbcal_db_version) {
                kbcal_install();
        }
}
add_action('plugins_loaded', 'kbcal_update_db_check');

function get_bookings($week_start)
{
        global $wpdb, $kbcal_table_name;

        $ret = array();
        $week_end = clone $week_start;
        $week_end->add(new DateInterval("P7D"));
        $week_start_string = $week_start->format(FORMAT_MYSQL);
        $week_end_string = $week_end->format(FORMAT_MYSQL);
        $sql = <<<EOF
                SELECT id,booked_from,booked_to,confirmed_at
                FROM $kbcal_table_name
                WHERE (booked_from >= "$week_start_string"
                       AND booked_from < "$week_end_string")
                OR (booked_to >= "$week_start_string"
                    AND booked_to < "$week_end_string")
                OR (booked_from < "$week_start_string"
                    AND booked_to > "$week_end_string")
                ORDER BY booked_from
EOF;
        $bookings = $wpdb->get_results($sql);

        if ($bookings) {
                foreach ($bookings as $booking) {
                        array_push($ret, array(
                                'id'   => $booking->id,
                                'from' => $booking->booked_from,
                                'to'   => $booking->booked_to,
                                'ok'   => $booking->confirmed_at));
                }
        }

        return $ret;
}

function is_booked($bookings, $date)
{
        foreach ($bookings as $booking) {
                if (date_create($booking["from"]) <= $date && \
                    date_create($booking["to"]) > $date) {
                        return $booking;
                }
        }

        return false;
}

/*
  Found in: http://www.phpbuilder.com/board/showthread.php?t=10222903
*/
function start_of_week($year, $week)
{
        $SoWdate = strtotime($year.'W'.str_pad($week,2,'0', STR_PAD_LEFT)."1");
        return date_create(date('Y-m-d', $SoWdate));
}

function start_of_week_by_date($date)
{
        $year = $date->format("Y");
        $week = $date->format("W");
        return start_of_week($year, $week);
}

function add_cell_date_offset($date, $row, $col)
{
        $ret = clone $date;

        date_modify($ret, "+$col days");

        $hours = $row / 2;
        date_modify($ret, "+".floor($hours)." hours");
        if ($hours != floor($hours))
                date_modify($ret, "+30 minutes");

        return $ret;
}

function get_booking_hash($id)
{
        return substr(sha1("KB20".$id),0,8);
}

function gen_calendar_block($year, $week)
{
        $week_start = start_of_week($year, $week);
        $weekdays = array('Mån', 'Tis', 'Ons', 'Tor', 'Fre', 'Lör', 'Sön');
        $table = "<table class=\"kbcal\">";

        $table .= "<tr><th>";
        $table .= "Vecka<br />$week";
        $table .= "</th>";
        $week_start_copy = clone $week_start;
        foreach ($weekdays as $weekday) {
                $month = $week_start_copy->format("m");
                $date = $week_start_copy->format("d");
                $table .= "<th>$weekday<br/>";
                $table .= "<sup>$date</sup>/<small>$month</small>";
                $table .= "</th>\n";
                $week_start_copy->add(new DateInterval("P1D"));
        }
        $table .= "</tr>\n";

        $bookings = get_bookings($week_start);

        for ($row = 0; $row < 48; $row++) {
                $rowclass = array("kbcal-row-$row");
                if ($row % 4 == 3) {
                        array_push($rowclass, "marker");
                }

                $table .= "<tr class=\"".implode(" ", $rowclass)."\">";

                if ($row % 4 == 0) {
                        $time = $row / 2;
                        $time = str_pad($time, 2, "0", STR_PAD_LEFT).":00";
                        $table .= "<th class=\"hour\" rowspan=\"4\">$time</th>";
                }

                for ($col = 0; $col < 7; $col++) {
                        $datetime = add_cell_date_offset($week_start, $row, $col);
			$cellclass = array("kbcal-$row-$col");
                        array_push($cellclass, "datetimecell");
                        $cellid = "kbcal_".$datetime->format("Ymd\\THis");
                        $booklink = "";
                        if (($booking = is_booked($bookings, $datetime)) !== false) {
				array_push($cellclass, "bokad");
                                if (current_user_can("edit_others_pages")) {
                                        if (!$booking["ok"])
                                                array_push($cellclass, "unconfirmed");
                                        $title = "Redigera bokning";
                                        $booklink = "<a href=\""
                                                . add_query_arg("confirm", get_booking_hash($booking["id"]))
                                                . "\"></a> ";
                                } else {
                                        $title = "Upptaget!";
                                }
			} else if (current_user_can("read")) {
                                $title = "Boka från ".$datetime->format("l H:i");
                                $booklink = "<a href=\"".
                                        add_query_arg("book", $datetime->format("Ymd\\THi")).
                                        "\"></a> ";
                        } else {
                                /* User not logged in */
                                $title = $datetime->format("l H:i");
                        }
                        $table .= "<td title=\"$title\" id=\"$cellid\" class=\"".implode(" ", $cellclass)."\">$booklink</td>";
                }
                $table .= "</tr>\n";
        }
        $table .= "</table>\n";

        return $table;
}

function kbcal_minutes_until_next_booking($basedate)
{
        global $wpdb, $kbcal_table_name;

        $basedate_string = $basedate->format(FORMAT_MYSQL);

        $db_result = $wpdb->get_results("
                SELECT id,booked_from,booked_to
                FROM $kbcal_table_name
                WHERE booked_from > \"$basedate_string\"
		   OR (booked_from <= \"$basedate_string\" AND
		       booked_to   >  \"$basedate_string\")
                ORDER BY booked_from
		LIMIT 1
                ");

        if (count($db_result) == 0)
                return 999999;

        $diff = $basedate->diff(date_create($db_result[0]->booked_from));

        /* År och månad är ointressant, men får inte resultera i 0
           så vi hittar på lite siffror. Typ. */
        $minutes  = $diff->y * $diff->m * 32 * 24 * 60;
        $minutes += $diff->d * (24 * 60);
        $minutes += $diff->h * 60;
        $minutes += $diff->i;

        if ($diff->invert)
                return -$minutes;
        return $minutes;
}

function kbcal_do_book($book_from, $duration, $comment)
{
        global $wp_query, $wpdb, $kbcal_table_name, $current_user;

        if (!current_user_can("read"))
                return "<h1>Insufficient privilegies</h1>";

        if ($duration % 30 != 0)
                return "<h1>Invalid duration</h1>";

        if ($duration > MAX_DURATION)
                return "<h1>Too long duration</h1>";

        $max_duration = kbcal_minutes_until_next_booking($book_from);
        if ($max_duration < $duration)
                return "<p>Ooops, seems occupied!</p>";

        $hours = floor($duration / 60);
        $minutes = $duration % 60;
        $book_to = clone $book_from;
        $book_to->add(new DateInterval("PT".$hours."H".$minutes."M"));

        get_currentuserinfo();
        $wpdb->query($wpdb->prepare("
		INSERT INTO $kbcal_table_name
		( booked_from, booked_to, booked_at, comment, uid, localid )
                VALUES(%s, %s, NOW(), %s, %d, 1)",
                                    $book_from->format(FORMAT_MYSQL),
                                    $book_to->format(FORMAT_MYSQL),
                                    $comment,
                                    $current_user->ID));

        // Mail to admin
        $link = get_permalink()."?confirm=".substr(sha1("KB20".$wpdb->insert_id),0,8);
        $name = $current_user->user_firstname." ".$current_user->user_lastname;
        $body = <<<EOF
$name har gjort en bokningsbegäran. Mer info här: 

$link
EOF;
        wp_mail("daniel@nystrom.st,mats@matse.st,rosangen@gmail.com,erske81@gmail.com",
		"[KB20] Bokningsförfrågan", $body,
                "From: KB20 Bokningssystem <no-reply@kb20.se>");

        // Mail to booker
        $body = <<<EOF
Tack för din bokningsbegäran!

Var god avvakta bekräftelsemail.

/ KB20-teamet
EOF;
        wp_mail($current_user->user_email, "[KB20] Bokningsbegäran mottagen", $body,
                "From: KB20 Bokningssystem <no-reply@kb20.se>");

        return <<<EOF
<h1>Bokningsbegäran mottagen</h1>
<p>Så snart en administratör behandlat din begäran skickas ett
e-post med mer information till dig.</p>
EOF;
}

function kbcal_gen_bookpage($atts)
{
        global $wp_query, $wpdb, $kbcal_table_name;

        if (!current_user_can("read"))
                return "<h1>Behörighet saknas</h1>";

        if (!($book_datetime = date_create($wp_query->get("book"))))
                return "<h1>Ogiltigt tidformat</h1>";

        $book_halfhour = $book_datetime->format("i");
        if ($book_halfhour != 0 && $book_halfhour != 30)
                return "<h1>Ogilitigt tidformat</h1>";

        if ($wp_query->get("duration"))
                return kbcal_do_book($book_datetime,
                                     $wp_query->get("duration"),
                                     $wp_query->get("comment"));

        $minutes_to_next_booking = kbcal_minutes_until_next_booking($book_datetime);
        if ($minutes_to_next_booking <= 0)
                return "aj aj aj, fullpruppat!".$minutes_to_next_booking." minuter fick vi fram här sörrö. Typ.";

        $max_duration = 4;
        if ($minutes_to_next_booking < 4 * 60)
                $max_duration = $minutes_to_next_booking / 60;

        $duration_values = "";
        for ($i = 0.5; $i <= $max_duration; $i += 0.5)
                $duration_values .= "<option value=\"".($i * 60)."\">$i timmar</option>";

        $book_string = $book_datetime->format("Y-m-d H:i");
        $book_iso = $book_datetime->format("Ymd\\THi");
        $ret = <<<EOF
		<form method="GET">
		<p>
                <input type="hidden" name="book" value="$book_iso" />
                <label for="from">Från:</label>
                <input type="text" name="from" id="from" disabled="disabled" value="$book_string" />
                <label for="duration">för</label>
                <select id="duration" name="duration">$duration_values</select>
		&raquo;
                <input type="submit" />
		</p><p>
		<label for="comment">Frivilligt meddelande till administratören:</label>
		</p>
		<textarea name="comment" id="comment"></textarea>
                </form>
EOF;

        return $ret;
}

function kbcal_get_booking($bookid, $hash = NULL)
{
        global $kbcal_table_name, $wpdb;

        if ($hash)
                $where = $wpdb->prepare(
                        "LEFT(SHA1(CONCAT(\"KB20\", id)), 8) = %s",
                        $hash);
        else
                $where = $wpdb->prepare("id = %d", $bookid);

        $booking = $wpdb->get_row("
                SELECT id,booked_from,booked_to,booked_at,confirmed_at,
                       localid, uid, comment
                FROM $kbcal_table_name
                WHERE $where
		LIMIT 1
                ");

        if (!$booking)
                return NULL;

        return array('id'           => $booking->id,
                     'booked_from'  => $booking->booked_from  != NULL ? date_create($booking->booked_from)  : NULL,
                     'booked_to'    => $booking->booked_to    != NULL ? date_create($booking->booked_to)    : NULL,
                     'booked_at'    => $booking->booked_at    != NULL ? date_create($booking->booked_at)    : NULL,
                     'confirmed_at' => $booking->confirmed_at != NULL ? date_create($booking->confirmed_at) : NULL,
                     'localid'      => $booking->localid,
                     'uid'          => $booking->uid,
                     'comment'      => $booking->comment);
}

function kbcal_view_booking($bookid, $booking = NULL)
{
        if ($booking == NULL)
                if (!($booking = kbcal_get_booking($bookid)))
                        return "<h1>Unknown bookid</h1>";

        $booker = get_userdata($booking["uid"]);
        $booking_hash = get_booking_hash($booking["id"]);
        $from = $booking["booked_from"]->format("D j M H:i");
        $to = $booking["booked_to"]->format("D j M H:i");
        $duration = $booking["booked_to"]->diff($booking["booked_from"])->format("%hh %im");
        $name = $booker->user_firstname." ".$booker->user_lastname;
        $ret = <<<EOF
                <div class="kbcal-view-booking">
                <form method="GET" action="">
                <input type="hidden" name="confirm" value="$booking_hash" />
                <table>
                <tr><th>Från:</th>  <th>Till:</th><th>Varar:</th>   <th>Av:</th></tr>
                <tr><td>$from</td>  <td>$to</td>  <td>$duration</td><td>$name</td></tr>
                </table>
EOF;

        if (strlen(trim($booking["comment"])) > 0) {
                $ret .=   "<table><tr><th>Kommentar:</th></tr>"
                        . "<tr><td>".$booking["comment"]."</td></tr>"
                        . "</table>";
        }

        $ret .=   "<h5>Kommentar till bokare:</h5>"
                . "<textarea name=\"comment\" id=\"comment\"></textarea>";

        if ($booking["confirmed_at"] == NULL)
                $ret .=   "<button name=\"action\" value=\"confirm\">Godkänn bokning</button> ";
        else
                $ret .=   "<h3>Bokningen redan godkänd!</h3>";

        if ($booking["confirmed_at"] == NULL)
                $ret .= "<button name=\"action\" value=\"remove\">Avslå bokning</button>";
        else
                $ret .= "<button name=\"action\" value=\"remove\">Ta bort bokning</button>";

        $ret .= "</form>";
        $ret .= "</div>";

        return $ret;
}

function kbcal_confirm_booking($bookhash, $comment = "")
{
        global $wpdb, $kbcal_table_name;

        $booking = kbcal_get_booking(NULL, $bookhash);

        $result = $wpdb->query($wpdb->prepare("
                UPDATE $kbcal_table_name
                SET confirmed_at = NOW()
                WHERE LEFT(SHA1(CONCAT(\"KB20\", id)), 8) = %s
                      AND confirmed_at IS NULL
                ", $bookhash));

        $booker = get_userdata($booking["uid"]);
        if (strlen(trim($comment)) > 0)
                $comment = "\nKommentar från administratör:\n\n".$comment."\n\n";
        $name = $booker->first_name." ".$booker->last_name;
        $from = $booking["booked_from"]->format(FORMAT_KBCAL);
        $to = $booking["booked_to"]->format(FORMAT_KBCAL);
        $body = <<<EOF
Hej $name!

Din bokning från $from till $to är godkänd!
$comment
/ KB20-teamet
EOF;
        wp_mail($booker->user_email, "[KB20] Bokning godkänd!", $body,
                "From: KB20 Bokningssystem <no-reply@kb20.se>");

        if ($result > 0)
                return <<<EOF
<h1>Bokning godkänd!</h1>
EOF;
        else
                return "<h1>Unknown booking</h1>";
}

function kbcal_remove_booking($bookhash, $comment = "")
{
        global $wpdb, $kbcal_table_name, $current_user;

        $booking = kbcal_get_booking(NULL, $bookhash);
        if (!$booking) 
                return "<h1>Unknown booking</h1>";

        $result = $wpdb->query($wpdb->prepare("
                DELETE FROM $kbcal_table_name
                WHERE LEFT(SHA1(CONCAT(\"KB20\", id)), 8) = %s
                ", $bookhash));

        // skicka mail till bokaren!!
        if (strlen(trim($comment)) > 0)
                $comment = "\n"."Kommentar av administratör:\n\n".$comment."\n";
        $booker = get_userdata($booking["uid"]);
        $from = $booking["booked_from"]->format(FORMAT_KBCAL);
        $to = $booking["booked_to"]->format(FORMAT_KBCAL);
        $body = <<<EOF
Hej $booker->user_name!

Din bokningsansökan för tiden $from till $to har blivit avslagen av administratör.
$comment
/ KB20-teamet
EOF;
        wp_mail($booker->user_email, "[KB20] Bokning avslagen", $body
                , "From: KB20 Bokningssystem <no-reply@kb20.se>");

        return "<h1>Bokning borttagen</h1>";
}

function kbcal_main($atts)
{
        global $wp_query, $current_user;
        $year = 0;
        $week = 0;

        if (($bookhash = $wp_query->get("confirm"))) {
                if ($wp_query->get("action") == "confirm")
                        return kbcal_confirm_booking($bookhash,
                                                     $wp_query->get("comment"));

                if ($wp_query->get("action") == "remove")
                        return kbcal_remove_booking($bookhash, 
                                                    $wp_query->get("comment"));

                $booking = kbcal_get_booking(NULL, $bookhash);

                return kbcal_view_booking(NULL, $booking);
        }

        if (($action = $wp_query->get("action"))
            && ($bookid = $wp_query->get("bookid"))) {
                return kbcal_confirm_booking(kbcal_get_booking($bookid));
        }

        if ($wp_query->get("book"))
                return kbcal_gen_bookpage($atts);

        if (preg_match('/^([0-9]{4})-([0-9]{2})$/', $wp_query->get("week"), $matches)) {
                $year = $matches[1];
                $week = $matches[2];
        } else {
                $year = date('Y');
                $week = date('W');
        }

        $selected_week = start_of_week($year, $week);

        $one_week = new DateInterval("P1W");
        $next_week = clone $selected_week;
        $next_week->add($one_week);
        $next_week_string = $next_week->format("Y-W");

        $prev_week = clone $selected_week;
        $prev_week->sub($one_week);
        $prev_week_string = $prev_week->format("Y-W");

        return    '<div class="kbcal-calendar-wrapper"><div class="kbcal-calendar">'
                . '  <div class="art-blockheader kbcal-blockheader">'
                . '    <div class="l"></div>'
                . '    <div class="r"></div>'
                . '    <h3 class="t"></h3>'
                . '  </div>'
                . '  <div class="kbcal-calendar-wrapper-wrapper">'
                . gen_calendar_block($selected_week->format("Y"),
                                  $selected_week->format("W"))
                . '    <div class="kbcal-ugly-spacer">&nbsp;</div>'
                . gen_calendar_block(
                        $next_week->format("Y"),
                        $next_week->format("W"))
                . '    <div style="clear: both;"></div>'
                . '  </div>'
                . '  <div class="kbcal-week-navigator">'
                . '    <a class="kbcal-week-previous" href="'
                .            add_query_arg("week", "$prev_week_string")
                .            '"> Föregående vecka</a>'
                . '    <a class="kbcal-week-next" href="'
                .            add_query_arg("week", "$next_week_string")
                .            '">Nästa vecka </a>'
                . '    <div style="clear: both;"></div>'
                . '  </div>'
                . '</div></div>';
}

function kbcal_load_stylesheets() {
        wp_enqueue_style('kb-plugin', plugins_url('kb-plugin.css', __FILE__));
}

function kbcal_query_vars($qvars)
{
        array_push($qvars, "week", "book", "duration", "confirm", "action", "comment");
        return $qvars;
}

add_action('wp_print_styles',  'kbcal_load_stylesheets');
add_filter('query_vars',       'kbcal_query_vars');
add_shortcode('kbkalender',    'kbcal_main');
