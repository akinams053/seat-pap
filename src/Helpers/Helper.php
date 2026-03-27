<?php

namespace Seat\Kassie\Calendar\Helpers;

class Helper
{
    /**
     * @param $importance
     * @param string $emoji_full
     * @param string $emoji_half
     * @param string $emoji_empty
     * @return string
     */
    public static function ImportanceAsEmoji($importance, string $emoji_full, string $emoji_half, string $emoji_empty): string
    {
        $output = "";

        $tmp = explode('.', (string)$importance);
        $val = $tmp[0];
        $dec = 0;

        if (count($tmp) > 1)
            $dec = $tmp[1];

        for ($i = 0; $i < $val; $i++)
            $output .= $emoji_full;

        $left = 5;
        if ($dec != 0) {
            $output .= $emoji_half;
            $left--;
        }

        for ($i = $val; $i < $left; $i++)
            $output .= $emoji_empty;

        return $output;
    }
}
