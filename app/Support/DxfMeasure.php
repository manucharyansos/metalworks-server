<?php

namespace App\Support;

/**
 * Measures a flat (laser) DXF for pricing: the cut length, how many separate
 * contours start a cut (pierces), the bend lines, the bounding size and the net
 * area (outer contours minus holes). Everything is returned in millimetres.
 *
 * Curves are sampled and placed through INSERT transforms, so arcs, bulged
 * polylines, ellipses, splines and blocks all count. Lines on a bend layer are
 * counted as bends, not cut; text, dimensions and hatches are not cut either.
 */
final class DxfMeasure
{
    private const MAX_BYTES = 30 * 1024 * 1024;
    private const MAX_POINTS = 3000000;
    private const MAX_DEPTH = 8;
    private const JOIN = 0.05; // mm between two ends that still meet
    private const BEND_LAYER = '/bend|ծռ|կռ|гиб/iu';
    private const UNITS = [0 => 1.0, 1 => 25.4, 2 => 304.8, 4 => 1.0, 5 => 10.0, 6 => 1000.0, 8 => 0.0000254, 9 => 0.0254, 10 => 914.4, 14 => 100.0];

    private array $blocks = [];
    private float $scale = 1.0;
    private int $points = 0;

    /** @return array{cut_length_mm: float, pierces: int, bends: int, width_mm: float, height_mm: float, area_mm2: ?float} */
    public static function file(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) throw new \InvalidArgumentException('DXF file is too large to measure.');
        $text = @file_get_contents($path);
        if ($text === false) throw new \InvalidArgumentException('DXF file cannot be read.');
        return self::text($text);
    }

    public static function text(string $text): array
    {
        if (str_starts_with($text, 'AutoCAD Binary DXF')) throw new \InvalidArgumentException('Binary DXF is not supported.');
        return (new self)->measure(self::pairs($text));
    }

    private function measure(array $pairs): array
    {
        [$header, $blocks, $entities] = self::sections($pairs);
        $units = (int) ($header['$INSUNITS'] ?? 0);
        $this->scale = self::UNITS[$units] ?? 1.0;
        foreach ($blocks as $name => $block) $this->blocks[$name] = $block;
        $paths = [];
        $bends = 0;
        foreach ($this->expand($entities, [1, 0, 0, 1, 0, 0], 0) as $path) {
            if ($path['bend']) { $bends++; continue; }
            $paths[] = $path;
        }
        if (!$paths) throw new \InvalidArgumentException('The DXF has no cut contours.');

        $length = 0.0;
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($paths as $path) {
            $points = $path['points'];
            for ($i = 1, $n = count($points); $i < $n; $i++) $length += hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]);
            foreach ($points as [$x, $y]) { $minX = min($minX, $x); $minY = min($minY, $y); $maxX = max($maxX, $x); $maxY = max($maxY, $y); }
        }
        [$pierces, $loops] = self::contours($paths);

        return [
            'cut_length_mm' => round($length, 1),
            'pierces' => $pierces,
            'bends' => $bends,
            'width_mm' => round($maxX - $minX, 1),
            'height_mm' => round($maxY - $minY, 1),
            'area_mm2' => self::netArea($loops),
        ];
    }

    /** ASCII DXF as [code, value] pairs. */
    private static function pairs(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $pairs = [];
        for ($i = 0, $n = count($lines) - 1; $i < $n; $i += 2) {
            $code = trim($lines[$i]);
            if ($code === '' || !preg_match('/^-?\d+$/', $code)) throw new \InvalidArgumentException('The file is not an ASCII DXF.');
            $pairs[] = [(int) $code, trim($lines[$i + 1])];
        }
        return $pairs;
    }

    /** Header variables, blocks by name (with base point), and model-space entities. */
    private static function sections(array $pairs): array
    {
        $header = [];
        $blocks = [];
        $entities = [];
        $n = count($pairs);
        for ($i = 0; $i < $n; $i++) {
            if ($pairs[$i] !== [0, 'SECTION'] || ($pairs[$i + 1][0] ?? null) !== 2) continue;
            $name = $pairs[$i + 1][1];
            $i += 2;
            $start = $i;
            while ($i < $n && $pairs[$i] !== [0, 'ENDSEC']) $i++;
            $body = array_slice($pairs, $start, $i - $start);
            if ($name === 'HEADER') {
                for ($j = 0, $m = count($body); $j < $m; $j++) {
                    if ($body[$j][0] === 9 && isset($body[$j + 1])) $header[$body[$j][1]] = $body[$j + 1][1];
                }
            } elseif ($name === 'BLOCKS') {
                $current = null;
                foreach (self::records($body) as $record) {
                    if ($record['type'] === 'BLOCK') {
                        $current = $record['data'][2][0] ?? ($record['data'][3][0] ?? null);
                        if ($current !== null) $blocks[$current] = ['base' => [(float) ($record['data'][10][0] ?? 0), (float) ($record['data'][20][0] ?? 0)], 'entities' => []];
                    } elseif ($record['type'] === 'ENDBLK') {
                        $current = null;
                    } elseif ($current !== null) {
                        $blocks[$current]['entities'][] = $record;
                    }
                }
            } elseif ($name === 'ENTITIES') {
                $entities = self::records($body);
            }
        }
        return [$header, $blocks, $entities];
    }

    /** Groups pairs into entity records; a POLYLINE keeps its VERTEX records. */
    private static function records(array $pairs): array
    {
        $records = [];
        $current = null;
        foreach ($pairs as [$code, $value]) {
            if ($code === 0) {
                if ($current && $current['type'] === 'POLYLINE' && in_array($value, ['VERTEX', 'SEQEND'], true)) {
                    if ($value === 'VERTEX') $current['vertices'][] = [];
                    else { $records[] = $current; $current = null; }
                    continue;
                }
                if ($current) $records[] = $current;
                $current = ['type' => $value, 'data' => [], 'pairs' => [], 'vertices' => []];
                continue;
            }
            if (!$current) continue;
            if ($current['type'] === 'POLYLINE' && $current['vertices']) {
                $current['vertices'][count($current['vertices']) - 1][] = [$code, $value];
                continue;
            }
            $current['data'][$code][] = $value;
            $current['pairs'][] = [$code, $value];
        }
        if ($current) $records[] = $current;
        return $records;
    }

    /** Entities as sampled paths in millimetres; INSERTs bring their blocks. */
    private function expand(array $records, array $matrix, int $depth): array
    {
        $paths = [];
        foreach ($records as $record) {
            $layer = (string) ($record['data'][8][0] ?? '0');
            if (strcasecmp($layer, 'DEFPOINTS') === 0) continue;
            if ($record['type'] === 'INSERT') {
                $name = $record['data'][2][0] ?? null;
                if ($depth >= self::MAX_DEPTH || $name === null || !isset($this->blocks[$name])) continue;
                $block = $this->blocks[$name];
                $sx = (float) ($record['data'][41][0] ?? 1) ?: 1.0;
                $sy = (float) ($record['data'][42][0] ?? 1) ?: 1.0;
                $angle = deg2rad((float) ($record['data'][50][0] ?? 0));
                [$cos, $sin] = [cos($angle), sin($angle)];
                $px = (float) ($record['data'][10][0] ?? 0);
                $py = (float) ($record['data'][20][0] ?? 0);
                // local → (minus base) → scale → rotate → move
                $local = [$cos * $sx, -$sin * $sy, $sin * $sx, $cos * $sy, 0, 0];
                $local[4] = $px - ($local[0] * $block['base'][0] + $local[1] * $block['base'][1]);
                $local[5] = $py - ($local[2] * $block['base'][0] + $local[3] * $block['base'][1]);
                foreach ($this->expand($block['entities'], self::compose($matrix, $local), $depth + 1) as $path) $paths[] = $path;
                continue;
            }
            $shape = $this->shape($record);
            if (!$shape) continue;
            $this->points += count($shape['points']);
            if ($this->points > self::MAX_POINTS) throw new \InvalidArgumentException('The DXF is too detailed to measure.');
            // Insert positions are in drawing units too, so units are applied last.
            $points = array_map(function ($p) use ($matrix) {
                [$x, $y] = self::apply($matrix, $p[0], $p[1]);
                return [$x * $this->scale, $y * $this->scale];
            }, $shape['points']);
            $paths[] = ['points' => $points, 'closed' => $shape['closed'], 'bend' => (bool) preg_match(self::BEND_LAYER, $layer)];
        }
        return $paths;
    }

    /** One entity as points in its own drawing units, or null when it is never cut. */
    private function shape(array $record): ?array
    {
        $d = $record['data'];
        $f = fn (int $code, float $default = 0.0) => isset($d[$code][0]) ? (float) $d[$code][0] : $default;
        // Arcs, circles and light polylines are drawn in their own plane: a
        // mirrored plane (extrusion z = -1) flips x.
        $mirror = $f(230, 1.0) < 0;
        $flip = fn (array $points) => $mirror ? array_map(fn ($p) => [-$p[0], $p[1]], $points) : $points;
        switch ($record['type']) {
            case 'LINE':
                return ['points' => [[$f(10), $f(20)], [$f(11), $f(21)]], 'closed' => false];
            case 'CIRCLE':
                return ['points' => $flip(self::arc($f(10), $f(20), $f(40), 0, 2 * M_PI)), 'closed' => true];
            case 'ARC':
                $start = deg2rad($f(50));
                $end = deg2rad($f(51));
                if ($end <= $start) $end += 2 * M_PI;
                return ['points' => $flip(self::arc($f(10), $f(20), $f(40), $start, $end)), 'closed' => false];
            case 'LWPOLYLINE':
                $vertices = [];
                foreach ($record['pairs'] as [$code, $value]) {
                    if ($code === 10) $vertices[] = [(float) $value, 0.0, 0.0];
                    elseif ($code === 20 && $vertices) $vertices[count($vertices) - 1][1] = (float) $value;
                    elseif ($code === 42 && $vertices) $vertices[count($vertices) - 1][2] = (float) $value;
                }
                return self::polyline($vertices, ((int) $f(70)) & 1, $flip);
            case 'POLYLINE':
                $flags = (int) $f(70);
                if ($flags & (16 | 64)) return null; // polygon meshes and polyface meshes are 3D bodies
                $vertices = [];
                foreach ($record['vertices'] as $vertex) {
                    $v = [0.0, 0.0, 0.0];
                    foreach ($vertex as [$code, $value]) {
                        if ($code === 10) $v[0] = (float) $value;
                        elseif ($code === 20) $v[1] = (float) $value;
                        elseif ($code === 42) $v[2] = (float) $value;
                    }
                    $vertices[] = $v;
                }
                return self::polyline($vertices, $flags & 1, $flip);
            case 'ELLIPSE':
                [$cx, $cy, $mx, $my] = [$f(10), $f(20), $f(11), $f(21)];
                $ratio = $f(40, 1.0);
                $start = $f(41);
                $end = $f(42, 2 * M_PI);
                if ($end <= $start) $end += 2 * M_PI;
                $full = abs(($end - $start) - 2 * M_PI) < 1e-6;
                $steps = max(16, (int) ceil(($end - $start) / (2 * M_PI) * 180));
                // Centre and axis are in world coordinates; a mirrored plane only turns the minor axis.
                $side = $mirror ? -1 : 1;
                $points = [];
                for ($i = 0; $i <= $steps; $i++) {
                    $t = $start + ($end - $start) * $i / $steps;
                    $points[] = [$cx + $mx * cos($t) - $side * $my * $ratio * sin($t), $cy + $my * cos($t) + $side * $mx * $ratio * sin($t)];
                }
                return ['points' => $points, 'closed' => $full];
            case 'SPLINE':
                return self::spline($record);
            default:
                return null; // TEXT, MTEXT, DIMENSION, HATCH, POINT, SOLID… are not cut
        }
    }

    private static function arc(float $cx, float $cy, float $r, float $start, float $end): array
    {
        $steps = max(8, (int) ceil(($end - $start) / (2 * M_PI) * 180));
        $points = [];
        for ($i = 0; $i <= $steps; $i++) {
            $a = $start + ($end - $start) * $i / $steps;
            $points[] = [$cx + $r * cos($a), $cy + $r * sin($a)];
        }
        return $points;
    }

    /** Vertices [x, y, bulge]; a bulge turns the segment after its vertex into an arc. */
    private static function polyline(array $vertices, int $closed, callable $flip): ?array
    {
        if (count($vertices) < 2) return null;
        $points = [[$vertices[0][0], $vertices[0][1]]];
        $count = count($vertices);
        $segments = $closed ? $count : $count - 1;
        for ($i = 0; $i < $segments; $i++) {
            [$x1, $y1, $bulge] = $vertices[$i];
            [$x2, $y2] = $vertices[($i + 1) % $count];
            if (abs($bulge) < 1e-9) { $points[] = [$x2, $y2]; continue; }
            $chord = hypot($x2 - $x1, $y2 - $y1);
            if ($chord < 1e-12) continue;
            $theta = 4 * atan($bulge); // signed sweep
            $radius = $chord / (2 * sin(abs($theta) / 2));
            $mx = ($x1 + $x2) / 2;
            $my = ($y1 + $y2) / 2;
            $offset = $radius * cos(abs($theta) / 2) * ($bulge > 0 ? 1 : -1);
            // The centre lies left of the chord for a positive bulge.
            $cx = $mx - $offset * ($y2 - $y1) / $chord;
            $cy = $my + $offset * ($x2 - $x1) / $chord;
            $a1 = atan2($y1 - $cy, $x1 - $cx);
            $steps = max(4, (int) ceil(abs($theta) / (2 * M_PI) * 180));
            for ($s = 1; $s <= $steps; $s++) {
                $a = $a1 + $theta * $s / $steps;
                $points[] = [$cx + $radius * cos($a), $cy + $radius * sin($a)];
            }
        }
        return ['points' => $flip($points), 'closed' => (bool) $closed];
    }

    /** A (possibly rational) B-spline evaluated with de Boor; fit points when it has no control points. */
    private static function spline(array $record): ?array
    {
        $controls = [];
        $fits = [];
        $knots = [];
        $weights = [];
        foreach ($record['pairs'] as [$code, $value]) {
            if ($code === 10) $controls[] = [(float) $value, 0.0];
            elseif ($code === 20 && $controls) $controls[count($controls) - 1][1] = (float) $value;
            elseif ($code === 11) $fits[] = [(float) $value, 0.0];
            elseif ($code === 21 && $fits) $fits[count($fits) - 1][1] = (float) $value;
            elseif ($code === 40) $knots[] = (float) $value;
            elseif ($code === 41) $weights[] = (float) $value;
        }
        $closed = (((int) ($record['data'][70][0] ?? 0)) & 1) === 1;
        $degree = (int) ($record['data'][71][0] ?? 3);
        $n = count($controls);
        if ($n < 2 || count($knots) !== $n + $degree + 1 || $degree < 1) {
            return count($fits) >= 2 ? ['points' => $fits, 'closed' => $closed] : ($n >= 2 ? ['points' => $controls, 'closed' => $closed] : null);
        }
        if (count($weights) !== $n) $weights = array_fill(0, $n, 1.0);
        $low = $knots[$degree];
        $high = $knots[$n];
        $steps = max(32, $n * 16);
        $points = [];
        for ($i = 0; $i <= $steps; $i++) {
            $t = $low + ($high - $low) * $i / $steps;
            $k = $degree;
            while ($k < $n - 1 && $t >= $knots[$k + 1]) $k++;
            $d = [];
            for ($j = 0; $j <= $degree; $j++) {
                $c = $controls[$k - $degree + $j];
                $w = $weights[$k - $degree + $j];
                $d[$j] = [$c[0] * $w, $c[1] * $w, $w];
            }
            for ($r = 1; $r <= $degree; $r++) {
                for ($j = $degree; $j >= $r; $j--) {
                    $left = $knots[$j + $k - $degree];
                    $right = $knots[$j + 1 + $k - $r];
                    $alpha = $right - $left == 0.0 ? 0.0 : ($t - $left) / ($right - $left);
                    for ($c = 0; $c < 3; $c++) $d[$j][$c] = (1 - $alpha) * $d[$j - 1][$c] + $alpha * $d[$j][$c];
                }
            }
            $w = $d[$degree][2] ?: 1.0;
            $points[] = [$d[$degree][0] / $w, $d[$degree][1] / $w];
        }
        return ['points' => $points, 'closed' => $closed];
    }

    /** [a b c d e f] maps (x, y) to (a·x + b·y + e, c·x + d·y + f). */
    private static function apply(array $m, float $x, float $y): array
    {
        return [$m[0] * $x + $m[1] * $y + $m[4], $m[2] * $x + $m[3] * $y + $m[5]];
    }

    private static function compose(array $outer, array $inner): array
    {
        return [
            $outer[0] * $inner[0] + $outer[1] * $inner[2], $outer[0] * $inner[1] + $outer[1] * $inner[3],
            $outer[2] * $inner[0] + $outer[3] * $inner[2], $outer[2] * $inner[1] + $outer[3] * $inner[3],
            $outer[0] * $inner[4] + $outer[1] * $inner[5] + $outer[4], $outer[2] * $inner[4] + $outer[3] * $inner[5] + $outer[5],
        ];
    }

    /**
     * Pierces are separate contours: every closed shape, and every chain of open
     * pieces whose ends meet. Chains that close are returned as loops for the area.
     */
    private static function contours(array $paths): array
    {
        $loops = [];
        $pierces = 0;
        $parent = [];
        $find = function ($key) use (&$parent, &$find) {
            while ($parent[$key] !== $key) $key = $parent[$key] = $parent[$parent[$key]];
            return $key;
        };
        // Ends closer than JOIN are one node; neighbouring cells are checked too,
        // so two ends on either side of a cell border still meet.
        $cells = [];
        $node = function (array $p) use (&$parent, &$cells) {
            $cx = (int) floor($p[0] / self::JOIN);
            $cy = (int) floor($p[1] / self::JOIN);
            for ($dx = -1; $dx <= 1; $dx++) for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($cells[($cx + $dx).':'.($cy + $dy)] ?? [] as [$x, $y, $key]) {
                    if (hypot($x - $p[0], $y - $p[1]) <= self::JOIN) return $key;
                }
            }
            $key = $cx.':'.$cy.':'.count($parent);
            $cells[$cx.':'.$cy][] = [$p[0], $p[1], $key];
            $parent[$key] = $key;
            return $key;
        };
        $open = [];
        foreach ($paths as $index => $path) {
            $points = $path['points'];
            $first = $points[0];
            $last = $points[count($points) - 1];
            if ($path['closed'] || hypot($first[0] - $last[0], $first[1] - $last[1]) <= self::JOIN) {
                $pierces++;
                $loops[] = $points;
                continue;
            }
            $a = $node($first);
            $b = $node($last);
            $parent[$find($a)] = $find($b);
            $open[] = [$index, $a, $b];
        }
        $groups = [];
        foreach ($open as [$index, $a, $b]) $groups[$find($a)][] = [$index, $a, $b];
        foreach ($groups as $members) {
            $pierces++;
            $loop = self::walk($members, $paths);
            if ($loop) $loops[] = $loop;
        }
        return [$pierces, $loops];
    }

    /** Joins a chain into one outline when each end meets exactly one other piece. */
    private static function walk(array $members, array $paths): ?array
    {
        $ends = [];
        foreach ($members as $position => [, $a, $b]) { $ends[$a][] = $position; $ends[$b][] = $position; }
        foreach ($ends as $list) if (count($list) !== 2) return null;
        $used = [];
        [$index, $start, $next] = $members[0];
        $points = $paths[$index]['points'];
        $used[0] = true;
        $at = $next;
        while (count($used) < count($members)) {
            $position = null;
            foreach ($ends[$at] as $candidate) if (!isset($used[$candidate])) { $position = $candidate; break; }
            if ($position === null) return null;
            $used[$position] = true;
            [$index, $a, $b] = $members[$position];
            $piece = $paths[$index]['points'];
            if ($a !== $at) $piece = array_reverse($piece);
            array_shift($piece);
            foreach ($piece as $point) $points[] = $point;
            $at = $a === $at ? $b : $a;
        }
        return $at === $start ? $points : null;
    }

    /** Outer outlines minus the holes in them (alternating by nesting depth). */
    private static function netArea(array $loops): ?float
    {
        if (!$loops || count($loops) > 5000) return null;
        $shapes = [];
        foreach ($loops as $points) {
            $area = 0.0;
            for ($i = 0, $n = count($points); $i < $n; $i++) {
                [$x1, $y1] = $points[$i];
                [$x2, $y2] = $points[($i + 1) % $n];
                $area += $x1 * $y2 - $x2 * $y1;
            }
            $shapes[] = ['points' => $points, 'area' => abs($area) / 2];
        }
        usort($shapes, fn ($a, $b) => $b['area'] <=> $a['area']);
        $total = 0.0;
        foreach ($shapes as $i => $shape) {
            $depth = 0;
            [$x, $y] = $shape['points'][0];
            for ($j = 0; $j < $i; $j++) if (self::inside($x, $y, $shapes[$j]['points'])) $depth++;
            $total += $depth % 2 === 0 ? $shape['area'] : -$shape['area'];
        }
        return round(max(0.0, $total), 1);
    }

    private static function inside(float $x, float $y, array $polygon): bool
    {
        $inside = false;
        for ($i = 0, $j = count($polygon) - 1, $n = count($polygon); $i < $n; $j = $i++) {
            [$xi, $yi] = $polygon[$i];
            [$xj, $yj] = $polygon[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $inside = !$inside;
        }
        return $inside;
    }
}
