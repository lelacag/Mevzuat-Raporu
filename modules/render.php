<?php
// render module
if (!function_exists('render_sparkline_svg')) {
    function render_sparkline_svg(array $data, $width = 420, $height = 80, $color = '#5a9a3c') {
        if (empty($data)) {
            return '<div class="card-box padded">No data</div>';
        }
        $n = count($data);
        $min = min($data);
        $max = max($data);
        if ($min === $max) { $min -= 1; $max += 1; }
        $stepX = ($n > 1) ? ($width / ($n - 1)) : $width;
        $pad = 4;
        $points = [];
        $xs=[]; $ys=[];
        foreach ($data as $i => $v) {
            $x = round($i * $stepX, 2);
            $y = $height - round((($v - $min) / ($max - $min)) * ($height - ($pad*2)),2) - $pad;
            $points[] = $x . ',' . $y;
            $xs[]=$x; $ys[]=$y;
        }
        $polyline = implode(' ', $points);
        $firstX = $xs[0] . ',' . $height;
        $lastX = end($xs) . ',' . $height;
        $polyfill = $firstX . ' ' . $polyline . ' ' . $lastX;
        $color_esc = htmlspecialchars($color, ENT_QUOTES);
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' role='img' aria-hidden='true' preserveAspectRatio='none'>";
        $svg .= "<defs><linearGradient id='g' x1='0' x2='0' y1='0' y2='1'><stop offset='0' stop-color='{$color_esc}' stop-opacity='0.15'/><stop offset='1' stop-color='{$color_esc}' stop-opacity='0.03'/></linearGradient></defs>";
        $svg .= "<polygon points='" . $polyfill . "' fill='url(#g)' />";
        $svg .= "<polyline points='" . $polyline . "' fill='none' stroke='" . $color_esc . "' stroke-width='2' stroke-linejoin='round' stroke-linecap='round' />";
        $svg .= "</svg>";
        return $svg;
    }
}

if (!function_exists('render_bar_chart_svg')) {
    function render_bar_chart_svg(array $items, $width = 360, $bar_height = 18, $color = '#5a9a3c') {
        if (empty($items)) return '<div class="card-box padded">Veri yok</div>';
        $max = max(array_column($items, 'value')) ?: 1;
        $label_w = 80; $gap = 8; $inner_w = max(40, $width - $label_w - 40);
        $height = count($items) * ($bar_height + $gap) + 8;
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' role='img' aria-hidden='true' preserveAspectRatio='none'>";
        $y = 6;
        foreach ($items as $item) {
            $label = htmlspecialchars($item['label']);
            $val = intval($item['value']);
            $w = round(($val / $max) * $inner_w, 2);
            $svg .= "<rect x='" . $label_w . "' y='" . $y . "' width='" . $inner_w . "' height='" . $bar_height . "' fill='#f1f3f5' rx='4' />";
            $svg .= "<rect x='" . $label_w . "' y='" . $y . "' width='" . $w . "' height='" . $bar_height . "' fill='" . htmlspecialchars($color, ENT_QUOTES) . "' rx='4' />";
            $svg .= "<text x='6' y='" . ($y + $bar_height/1.6) . "' font-family='sans-serif' font-size='12' fill='#333'>" . $label . "</text>";
            $svg .= "<text x='" . ($label_w + $inner_w + 6) . "' y='" . ($y + $bar_height/1.6) . "' font-family='sans-serif' font-size='12' fill='#666'>" . $val . "</text>";
            $y += $bar_height + $gap;
        }
        $svg .= "</svg>";
        return $svg;
    }
}
