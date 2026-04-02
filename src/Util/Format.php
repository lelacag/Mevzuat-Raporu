<?php
namespace App\Util;

class Format
{
    public static function time(string $ts): string { return format_time($ts); }
    public static function sparkline(array $data, int $w = 420, int $h = 80, string $c = '#5a9a3c'): string { return render_sparkline_svg($data, $w, $h, $c); }
    public static function barChart(array $items, int $w = 360, int $bh = 18, string $c = '#5a9a3c'): string { return render_bar_chart_svg($items, $w, $bh, $c); }
}