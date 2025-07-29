<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides simple static methods for encoding
 * share urls for main social platforms
 */

class _uho_social
{
    private static $initialized = false;

    /**
     * Constructor
     * @return null
     */

    private static function initialize()
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }

    /**
     * Returns FB share url
     * @param string $url
     * @return string
     */

    public static function getFacebookShare($url)
    {
        self::initialize();
        $url = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($url);
        return ($url);
    }

    /**
     * Returns LinkedIn share url
     * @param string $url
     * @return string
     */

    public static function getLinkedinShare($url)
    {
        self::initialize();
        $url = 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode($url);
        return ($url);
    }

    /**
     * Returns Pinterest share url
     * @param string $url
     * @param string $title
     * @param string $image
     * @return string
     */

    public static function getPinterestShare($url, $title, $image)
    {
        self::initialize();
        $url = 'https://pinterest.com/pin/create/button/?url=' . urlencode($url) . '&amp;media=' . urlencode($image) . '&amp;description=' . urlencode($title);
        return ($url);
    }

    /**
     * Returns Twitter share url
     * @param string $url
     * @param string $title
     * @return string
     */

    public static function getTwitterShare($url, $title)
    {
        self::initialize();
        if (!$title || !is_string($title)) $title = '';
        if ($url && is_string($url)) $url = 'https://www.twitter.com/share?text=' . urlencode($title) . '&url=' . urlencode($url);
        return ($url);
    }

    /**
     * Returns Email share url
     * @param string $url
     * @param string $title
     * @return string
     */

    public static function getEmailShare($url, $title = null)
    {
        self::initialize();
        $br = "%0D%0A";
        $subject = $title;
        if ($title) $body = str_replace(' ', '%20', $title);
        else $body = '';
        if (is_string($subject) && is_string($url))
            $url = 'mailto:?subject=' . $subject . '&body=' . $body . $br . $br . $url;
        else $url = null;
        return ($url);
    }

    /**
     * Converts Twitter status message
     * @param string $status
     * @param boolean $targetBlank
     * @return string
     */

    public static function twitterStatusUrlConvert($status, $targetBlank = true)
    {
        $target = $targetBlank ? " target=\"_blank\" " : "";
        $status = preg_replace("/(#([_a-z0-9\-]+))/i", "<a href=\"https://twitter.com/search?q=%23$2\" title=\"Search $1\" $target >$1</a>", $status);
        return $status;
    }

    /**
     * Converts Instagram status message
     * @param string $status
     * @param boolean $targetBlank
     * @return string
     */

    public static function instagramStatusUrlConvert($status, $targetBlank = true)
    {
        $target = $targetBlank ? " target=\"_blank\" " : "";
        $status = preg_replace("/(#([_a-z0-9\-]+))/i", "<a href=\"https://www.instagram.com/explore/tags/$2\" title=\"Search $1\" $target >$1</a>", $status);
        return $status;
    }

    /**
     * Converts YouTube status message
     * @param string $status
     * @param boolean $targetBlank
     * @return string
     */

    public static function youtubeStatusUrlConvert($status, $targetBlank = true)
    {
        $target = $targetBlank ? " target=\"_blank\" " : "";
        $status = preg_replace("/(#([_a-z0-9\-]+))/i", "<a href=\"https://www.youtube.com/results?q=%23$2\" title=\"Search $1\" $target >$1</a>", $status);
        return $status;
    }
}
