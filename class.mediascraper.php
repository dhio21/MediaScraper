<?php

class MediaScraper {

    var $db = [
        "connect" => "", //database connection variable
        "table" => "",
        "colSearch" => "", //varchar 255
        "colMedia" => "", //varchar 255
        "colSite" => "", //varchar 16. Stores the key from $sites
        "colTime" => "", //datetime
        "colPrefix" => "" //varchar 16. For example, stores what script it was executed from
    ];
    var $sites = [
        "bing" => "http://www.bing.com/images/search?setlang=sv-se&q=",
        "google" => "https://www.google.se/search?tbm=isch&q=",
        "youtube" => "https://www.youtube.com/results?search_query="
    ];
    var $scrape = ["bool" => true, "url" => "", "hourLimit" => 10];
    var $keyword, $mediaType, $mediaVal, $imagePath, $status, $result;

    /*
     * @param string $keyword - word to search image for
     * @param string $media - image or video-code
     * @param string $site - key in assoc $sites array, e.g. bing/google/youtube
     * @param boolean $scrapeBool - if false, only check db for old media
     * @param int $hourLimit - max requests to specific site per hour, avoid ip-ban
     * @param string $path - path to the destination image-folder, not used if $media='video'
     * @param string $prefix - get stored in db, has no functionality
     * @param array $dbConfig - check assoc array $db for structure
     */

    public function __construct($keyword, $media, $site, $scrapeBool, $hourLimit, $path, $prefix, $dbConfig) {
        $this->db = $dbConfig;
        $this->mediaType = $media;
        if ($this->mediaType == 'image') {
            $this->mediaVal = date('YmdHis') . '_' . rand(10, 99) . '.jpeg';
            $this->imagePath = $path;
        } else if ($this->mediaType == 'video') {
            $this->mediaVal = ''; //will be youtube-code
        } else {
            echo '<br>MediaScraper: Fel mediatyp<br>';
        }

        $this->keyword = $this->_get_cleanKeyword($keyword);
        $this->site = $site;
        $this->prefix = $prefix;

        $this->scrape["url"] = $this->_get_scrapeUrl($site);
        $this->scrape["bool"] = $scrapeBool;
        $this->scrape["hourLimit"] = $hourLimit;

        $this->status = false;
        $this->message = '';
        $this->result = [];
    }

    public function init() {
        if ($this->_is_dbError()) {
            return ["status" => false, "msg" => "Wrong db-config"];
        }
        $inp = $this->db["connect"]->real_escape_string($this->keyword);
        $sql = "SELECT * FROM " . $this->db["table"] . " WHERE " . $this->db["colSearch"] . "='$inp' AND " . $this->db["colSite"] . "='$this->site' LIMIT 1";
        $query = $this->db["connect"]->query($sql);
        if ($query->num_rows > 0) {
            /* Old media found in db */
            $this->_onSuccess("Old media found", $query->fetch_assoc());
        } else if ($this->scrape["bool"] && $this->_get_requestsLastHour() < $this->scrape["hourLimit"]) {
            /* Scrape site */
            $html = @file_get_contents($this->scrape["url"]);
            if ($html === FALSE) {
                $this->_onFail("Cant access " . $this->scrape["url"]);
            } else if ($this->mediaType == 'image') {
                $this->_handleImage($html);
            } else if ($this->mediaType == 'video') {
                $this->_handleVideo($html);
            }
        } else {
            $this->_onFail("Time limit exceeded or scrapeBool = false");
        }
    }

    public function get_result() {
        return [
            "status" => $this->status,
            "message" => $this->message,
            "result" => $this->result
        ];
    }

    private function _handleImage($html) {
        if (!preg_match('/<*img[^>]*src=*["\']?(http[^"\']*)/i', $html, $arr)) {
            $this->_onFail("No images found on " . $this->scrape["url"]);
            return;
        }
        $img = $arr[1];
        $headers = @get_headers($img);
        if (strpos($headers[0], '200') === false) {
            $this->_onFail("Couldnt fetch image: " . $headers[0]);
            return;
        } else if ($this->_insertRow() === FALSE) {
            $this->_onFail("Couldnt save img-info in db");
            return;
        }
        $id = $this->db["connect"]->insert_id;
        copy($img, $this->imagePath . '/' . $this->mediaVal);
        $row = $this->_selectRow();
        $this->_onSuccess("New image scraped", $row);
        return;
    }

    private function _handleVideo($html) {
        if (!preg_match('/href\=\"\/watch\?v=([a-z0-9_-]+)\"/i', $html, $arr)) {
            $this->_onFail("No video-links found on " . $this->scrape["url"]);
            return;
        }
        $this->mediaVal = $arr[1];
        if ($this->_insertRow() === FALSE) {
            $this->_onFail("Couldnt save video-info in db");
            return;
        }
        $id = $this->db["connect"]->insert_id;
        $row = $this->_selectRow();
        $this->_onSuccess("New youtube-code scraped", $row);
        return;
    }

    private function _insertRow() {
        $date = date('Y-m-d H:i:s');
        $inp = $this->db["connect"]->real_escape_string($this->keyword);
        $sql = "INSERT INTO " . $this->db["table"] . " ";
        $sql .="(" . $this->db["colSearch"] . ',' . $this->db["colMedia"] . ',' . $this->db["colSite"] . ',' . $this->db["colTime"] . ',' . $this->db["colPrefix"] . ")";
        $sql .="VALUES ('$inp','$this->mediaVal','$this->site','$date','$this->prefix')";
        return $this->db["connect"]->query($sql);
    }

    private function _selectRow() {
        $sql = $this->_get_selectSql();
        $query = $this->db["connect"]->query($sql);
        return $query->fetch_assoc();
    }

    private function _get_selectSql() {
        $sql = "SELECT * FROM " . $this->db["table"];
        $sql .= " WHERE " . $this->db["colMedia"] . "='$this->mediaVal' AND " . $this->db["colSite"] . "='$this->site' LIMIT 1";
        return $sql;
    }

    private function _is_dbError() {
        $sql = "SELECT " . $this->db["colSearch"] . ',' . $this->db["colMedia"] . ',' . $this->db["colSite"] . ',' . $this->db["colTime"] . ',' . $this->db["colPrefix"];
        $sql .= " FROM " . $this->db["table"];
        $sql .= " WHERE " . $this->db["colSearch"] . "='' LIMIT 1";
        //   echo $sql;
        if ($this->db["connect"]->query($sql)) {
            return false;
        }
        return true;
    }

    private function _get_requestsLastHour() {
        $date = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $sql = "SELECT * FROM " . $this->db["table"] . " WHERE " . $this->db["colSite"] . "='" . $this->site . "' AND " . $this->db["colTime"] . ">'$date'";
        $query = $this->db["connect"]->query($sql);
        return $query->num_rows;
    }

    private function _onSuccess($msg, $row) {
        $this->status = true;
        $this->message = $msg;
        $this->result = $row;
    }

    private function _onFail($msg) {
        $this->status = false;
        $this->message = $msg;
    }

    private function _get_cleanKeyword($str) {
        return mb_strtolower(trim($str));
    }

    private function _get_scrapeUrl($site) {
        if (!isset($this->sites[$site])) {
            return ''; //site isnt configured
        }
        return $this->sites[$site] . urlencode($this->keyword);
    }

    public function __toString() {
        $inp = $this->db["connect"]->real_escape_string($this->keyword);
        $sql = "SELECT * FROM " . $this->db["table"] . " WHERE " . $this->db["colSearch"] . "='$inp' AND " . $this->db["colSite"] . "='$this->site' LIMIT 1";
        $out ='Search query: ' . htmlspecialchars($this->keyword);
        $out .='<br>Media type: ' . $this->mediaType;
        $out .='<br>SQL select: ' . htmlspecialchars($sql);
        $out .='<br>Scrape on: ';
        $out .=($this->scrape["bool"]) ? 'true' : 'false';
        $out .='<br>URL to scrape: ' . $this->scrape["url"];


        return $out;
    }

}

?>