<?php
/**
 * Time formatting helper functions
 * Handles timezone conversions between UTC (database) and local timezone (display)
 */

if (!function_exists('format_time')) {
    /**
     * Format a timestamp for display, converting from UTC to local timezone
     *
     * @param string|DateTimeInterface $timestamp Database timestamp (assumed UTC)
     * @param string $format Output format (default: 'Y-m-d H:i:s')
     * @return string Formatted time in local timezone
     */
    function format_time($timestamp, $format = 'Y-m-d H:i:s') {
        if (empty($timestamp)) {
            return '';
        }

        // If it's already a DateTime object, clone it
        if ($timestamp instanceof DateTimeInterface) {
            $date = clone $timestamp;
        } else {
            // Create DateTime object from string, assuming it's in UTC
            $date = new DateTime($timestamp, new DateTimeZone('UTC'));
        }

        // Convert to the application's timezone (Europe/Istanbul by default)
        $timezone = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Europe/Istanbul');
        $date->setTimezone($timezone);

        // Format the date
        return $date->format($format);
    }
}

if (!function_exists('format_time_relative')) {
    /**
     * Format time as relative string (e.g., "2 hours ago")
     *
     * @param string|DateTimeInterface $timestamp Database timestamp (assumed UTC)
     * @return string Relative time string
     */
    function format_time_relative($timestamp) {
        if (empty($timestamp)) {
            return '';
        }

        if ($timestamp instanceof DateTimeInterface) {
            $date = clone $timestamp;
        } else {
            $date = new DateTime($timestamp, new DateTimeZone('UTC'));
        }

        // Convert to local timezone
        $timezone = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Europe/Istanbul');
        $date->setTimezone($timezone);

        $now = new DateTime('now', $timezone);
        $diff = $now->diff($date);

        if ($diff->y > 0) {
            return $diff->y . ' yıl önce';
        } elseif ($diff->m > 0) {
            return $diff->m . ' ay önce';
        } elseif ($diff->d > 0) {
            if ($diff->d == 1) {
                return 'dün';
            } else {
                return $diff->d . ' gün önce';
            }
        } elseif ($diff->h > 0) {
            return $diff->h . ' saat önce';
        } elseif ($diff->i > 0) {
            return $diff->i . ' dakika önce';
        } else {
            return 'az önce';
        }
    }
}

if (!function_exists('format_time_for_database')) {
    /**
     * Convert a local time to UTC for database storage
     *
     * @param string|DateTimeInterface $localTime Time in local timezone
     * @return string Time in UTC (ISO format)
     */
    function format_time_for_database($localTime) {
        if (empty($localTime)) {
            return null;
        }

        if ($localTime instanceof DateTimeInterface) {
            $date = clone $localTime;
        } else {
            // Assume input is in local timezone
            $timezone = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Europe/Istanbul');
            $date = new DateTime($localTime, $timezone);
        }

        // Convert to UTC
        $date->setTimezone(new DateTimeZone('UTC'));
        return $date->format('Y-m-d H:i:s');
    }
}