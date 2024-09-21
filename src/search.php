<?php

class SynoDLMSearchNCore
{
    private $query_url = "https://ncore.pro/torrents.php";
    private $login_url = "https://ncore.pro/login.php";
    private $cookie_path = "/tmp/ncore.cookie";
    private $fallback_useragent = "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/535 (KHTML, like Gecko) Chrome/14 Safari/535";
    private $debug = false;

    ///////////////////
    // Synology methods
    ///////////////////

    public function prepare($curl, $query, $username = null, $password = null)
    {
        $this->log("Prepare called with query: $query");
        if (!file_exists($this->cookie_path) && $username !== null && $password !== null) {
            $this->VerifyAccount($username, $password);
        }

        $this->configureCurl($curl);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookie_path);
        curl_setopt($curl, CURLOPT_REFERER, $this->query_url);
        curl_setopt(
            $curl,
            CURLOPT_URL,
            $this->query_url . "?miben=name&tipus=all_own&miszerint=fid&hogyan=DESC&mire=" . urlencode("[###]" != $query ? $query : "")
        );
    }

    public function VerifyAccount($username, $password)
    {
        $this->log("VerifyAccount called with username: $username");

        $user = $username;
        $mfa_code = "";
        $url = $this->login_url;

        // Handle 2fa code appended to username: username|2fa_code
        if (str_contains($username, '|')) {
            $x = explode("|", $username);
            $user = $x[0];
            $mfa_code = $x[1];
            $this->log("Two-factor login with user $user and code $mfa_code");
            $url = $this->login_url . "?2fa";
        }

        $post_data = array(
            "ne_leptessen_ki" => "1",
            "Submit" => "Belépés!",
            "nev" => $user,
            "pass" => $password,
            "set_lang" => "hu",
            "submitted" => 1
        );
        if ($mfa_code) {
            $post_data["2factor"] = $mfa_code;
        }

        $post_data = http_build_query($post_data);

        $curl = curl_init();
        $this->configureCurl($curl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);

        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookie_path);
        curl_setopt($curl, CURLOPT_REFERER, $url);
        curl_setopt($curl, CURLOPT_URL, $url);

        $login_info = curl_exec($curl);
        curl_close($curl);
        // after php 8.0.0 curl_close() is noop,
        // call unset() to save the cookie file
        // https://www.php.net/manual/en/curl.constants.php#constant.curlopt-cookiejar
        unset($curl);

        if (
            false != $login_info
            && preg_match("/Set-Cookie: nick=" . $user . "/iU", $login_info)
            && file_exists($this->cookie_path)
        ) {
            $this->log("Succesful login with user: $user");
            return true;
        }
        $this->log("Can't login with user: $user");
        return false;
    }

    public function parse($plugin, $response)
    {
        $this->log("Parse called");
        preg_match("/(rss.php.*)(key=................................)/", $response, $key_k);
        $key = $key_k[2];

        preg_match("/<div.*?box_torrent_all.*?>/iU", $response, $match);
        $beginPos = strpos($response, $match[0]);
        preg_match("/<div.*?lista_lab.*?>/iU", $response, $match);
        $endPos = strpos($response, $match[0]);

        $response = substr($response, $beginPos, $endPos - $beginPos);
        preg_match("/<div.*\"box_torrent_all\".*>(.*?)<\/div>/isU", $response, $match);

        $response = $match[1];

        if (preg_match_all("/<div class=\"box_torrent\">(.*)<div class=\"torrent_lenyilo/siU", $response, $matches2, PREG_SET_ORDER)) {
            foreach ($matches2 as $match2) {
                $title = "Unknown title";
                $download = "Unknown download";
                $size = 0;
                $datetime = "1978-09-28";
                $page = "Default page";
                $hash = "Hash unknown";
                $seeds = 0;
                $leechs = 0;
                $category = "Unknown category";

                $torrentData = $match2[1];
                preg_match("/"
                    . "<div class=\"box_alap_img\">.*<a.*<img.*alt=['\"](.*)['\"].*"
                    . "<div class=\"torrent_txt2?\">.*<a.*href=['\"].*id=(\d+)['\"].*(title=['\"](.*)['\"].*)<nobr>(.*)<\/nobr>.*"
                    . "box_feltoltve.*>(.*)<\/.*"
                    . "box_meret2.*>(.*)<\/.*"
                    . "box_s.*<a.*>(\d+)<\/.*"
                    . "box_l.*<a.*>(\d+)<\/.*"
                    . "/isU", $torrentData, $matchDetail);
                if (count($matchDetail) > 0) {
                    $title = $this->getTitle($matchDetail);
                    $download = $this->query_url . "?action=download&id=" . $matchDetail[2] . "&" . $key;
                    $size = $this->getSize($matchDetail);
                    $datetime = $this->getDate($matchDetail);
                    $page = $this->query_url . "?action=details&id=" . $matchDetail[2];
                    $hash = md5($title . $download);
                    $seeds = $matchDetail[8];
                    $leechs = $matchDetail[9];
                    $category =  $matchDetail[1];

                    $plugin->addResult($title, $download, $size, $datetime, $page, $hash, $seeds, $leechs, $category);
                } else {
                    ob_start();
                    var_dump($torrentData);
                    file_put_contents("/tmp/ncore_dlm_parse_error_" . date("YmdHis") . "_" . rand(100000) . ".txt", ob_get_clean() . "\n");
                }
            }
        }
    }

    /////////////////
    // Helper methods
    /////////////////

    private function log($str)
    {
        if ($this->debug == true) {
            $date = date("Y-m-d H:i:s", time());
            file_put_contents("/tmp/ncore.log", "{$date}: {$str}\n", FILE_APPEND);
        }
    }

    private function configureCurl($curl)
    {
        curl_setopt($curl, CURLOPT_FAILONERROR, true); // fail verbosely if the HTTP code returned is greater than or equal to 400
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); // follow any "Location: " header

        $useragent = defined("DOWNLOAD_STATION_USER_AGENT") ? DOWNLOAD_STATION_USER_AGENT : $this->fallback_useragent;
        curl_setopt($curl, CURLOPT_USERAGENT, $useragent);

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // "0" to not check the host name to cert, "2" to verify Common Name field or Subject Alternate Name field
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1); // "0" to not check cert validity, "1" to check cert validity

        curl_setopt($curl, CURLOPT_HEADER, true); // include the header in the output
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // return the transfer as a string
    }

    private function getTitle($matches)
    {
        return ("..." == substr($matches[5], -3) && trim($matches[4]) != "" ? $matches[4] : $matches[5]);
    }

    private function getSize($matches)
    {
        $size = str_replace(array("<br>", "<br />", "<br/>"), array(" "), $matches[7]);
        preg_match("/(.*) (.*)/i", $size, $sizeParts);
        $multiplier = 1;
        switch ($sizeParts[2]) {
            case "TiB":
                $multiplier *= 1024;
            case "GiB":
                $multiplier *= 1024;
            case "MiB":
                $multiplier *= 1024;
            case "KiB":
                $multiplier *= 1024;
                break;
        }
        return $sizeParts[1] * $multiplier;
    }

    private function getDate($matches)
    {
        $date = $matches[6];
        if (preg_match("/(\d+)-(\d+)-(\d+).*?(\d+):(\d+):(\d+)/i", $date, $matchDate)) {
            $date = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $matchDate[1], $matchDate[2], $matchDate[3], $matchDate[4], $matchDate[5], $matchDate[6]);
        }
        return $date;
    }
}
