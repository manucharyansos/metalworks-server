<?php

namespace Tests\Unit;

use App\Support\DxfMeasure;
use PHPUnit\Framework\TestCase;

class DxfMeasureTest extends TestCase
{
    public function test_a_plate_with_a_hole_has_two_pierces_and_its_net_area(): void
    {
        $m = DxfMeasure::text($this->dxf([
            $this->lwpolyline([[0, 0], [100, 0], [100, 50], [0, 50]], true),
            $this->circle(50, 25, 5),
        ]));
        $this->assertEqualsWithDelta(300 + 2 * M_PI * 5, $m['cut_length_mm'], 0.2);
        $this->assertSame(2, $m['pierces']);
        $this->assertSame(0, $m['bends']);
        $this->assertSame([100.0, 50.0], [$m['width_mm'], $m['height_mm']]);
        $this->assertEqualsWithDelta(5000 - M_PI * 25, $m['area_mm2'], 0.5);
    }

    public function test_loose_lines_join_into_one_outline_and_bend_lines_are_not_cut(): void
    {
        $m = DxfMeasure::text($this->dxf([
            $this->line(0, 0, 100, 0), $this->line(100, 0, 100, 50.00001), $this->line(100, 50, 0, 50), $this->line(0, 50, 0, 0),
            $this->line(40, 0, 40, 50, 'BEND_LINES'),
        ]));
        $this->assertEqualsWithDelta(300, $m['cut_length_mm'], 0.01);
        $this->assertSame(1, $m['pierces']);
        $this->assertSame(1, $m['bends']);
        $this->assertEqualsWithDelta(5000, $m['area_mm2'], 0.01);
    }

    public function test_bulged_polyline_arcs_are_measured_as_arcs(): void
    {
        // A slot: two 30 mm sides and two half circles of radius 5.
        $m = DxfMeasure::text($this->dxf([$this->lwpolyline([[0, 0, 0], [30, 0, 1], [30, 10, 0], [0, 10, 1]], true)]));
        $this->assertEqualsWithDelta(60 + 2 * M_PI * 5, $m['cut_length_mm'], 0.1);
        $this->assertEqualsWithDelta(40, $m['width_mm'], 0.1);
        $this->assertEqualsWithDelta(300 + M_PI * 25, $m['area_mm2'], 0.5);
        $this->assertSame(1, $m['pierces']);
    }

    public function test_blocks_are_placed_and_scaled_and_inches_become_millimetres(): void
    {
        $block = "0\nBLOCK\n8\n0\n2\nHOLE\n70\n0\n10\n5\n20\n5\n".$this->circle(5, 5, 10)."0\nENDBLK\n8\n0\n";
        $m = DxfMeasure::text($this->dxf(["0\nINSERT\n8\n0\n2\nHOLE\n10\n100\n20\n100\n41\n2\n42\n2\n"], 4, $block));
        $this->assertEqualsWithDelta(2 * M_PI * 20, $m['cut_length_mm'], 0.1);
        $this->assertEqualsWithDelta(40, $m['width_mm'], 0.1);

        $inch = DxfMeasure::text($this->dxf([$this->lwpolyline([[0, 0], [1, 0], [1, 1], [0, 1]], true)], 1));
        $this->assertEqualsWithDelta(4 * 25.4, $inch['cut_length_mm'], 0.01);
        $this->assertEqualsWithDelta(25.4 * 25.4, $inch['area_mm2'], 0.1);
    }

    public function test_an_r12_polyline_keeps_its_bulges_and_a_bend_line_stays_a_bend(): void
    {
        // The form the customer's template builder writes: POLYLINE, VERTEX, SEQEND.
        $m = DxfMeasure::text($this->dxf([
            $this->polyline([[10, 0, 0], [110, 0, 1], [110, 20, 0], [10, 20, 1]]),
            $this->circle(10, 10, 4),
            $this->line(60, 0, 60, 20, 'BEND'),
        ]));
        $this->assertEqualsWithDelta(200 + 2 * M_PI * 10 + 2 * M_PI * 4, $m['cut_length_mm'], 0.2);
        $this->assertSame(2, $m['pierces']);
        $this->assertSame(1, $m['bends']);
        $this->assertSame([120.0, 20.0], [$m['width_mm'], $m['height_mm']]);
        $this->assertEqualsWithDelta(100 * 20 + M_PI * 100 - M_PI * 16, $m['area_mm2'], 1);
    }

    public function test_a_sheet_with_thousands_of_holes_is_measured_quickly(): void
    {
        $holes = [];
        for ($i = 0; $i < 50; $i++) {
            for ($j = 0; $j < 50; $j++) $holes[] = $this->circle(10 + $i * 20, 10 + $j * 20, 4);
        }
        $started = microtime(true);
        $m = DxfMeasure::text($this->dxf([$this->lwpolyline([[0, 0], [1000, 0], [1000, 1000], [0, 1000]], true), ...$holes]));
        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertSame(2501, $m['pierces']);
        $this->assertEqualsWithDelta(1000000 - 2500 * M_PI * 16, $m['area_mm2'], 1250);
    }

    public function test_files_that_cannot_be_measured_are_refused(): void
    {
        foreach (['not a drawing', "AutoCAD Binary DXF\r\n\x1a\x00", $this->dxf(["0\nTEXT\n8\n0\n10\n0\n20\n0\n40\n5\n1\nHello\n"])] as $text) {
            try {
                DxfMeasure::text($text);
                $this->fail('Measured a file without cut contours');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function dxf(array $entities, int $units = 4, string $blocks = ''): string
    {
        return "0\nSECTION\n2\nHEADER\n9\n\$INSUNITS\n70\n{$units}\n0\nENDSEC\n"
            ."0\nSECTION\n2\nBLOCKS\n{$blocks}0\nENDSEC\n"
            ."0\nSECTION\n2\nENTITIES\n".implode('', $entities)."0\nENDSEC\n0\nEOF\n";
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $layer = '0'): string
    {
        return "0\nLINE\n8\n{$layer}\n10\n{$x1}\n20\n{$y1}\n11\n{$x2}\n21\n{$y2}\n";
    }

    private function circle(float $x, float $y, float $r): string
    {
        return "0\nCIRCLE\n8\n0\n10\n{$x}\n20\n{$y}\n40\n{$r}\n";
    }

    private function polyline(array $vertices): string
    {
        $text = "0\nPOLYLINE\n8\n0\n66\n1\n10\n0\n20\n0\n30\n0\n70\n1\n";
        foreach ($vertices as $v) $text .= "0\nVERTEX\n8\n0\n10\n{$v[0]}\n20\n{$v[1]}\n30\n0\n".(!empty($v[2]) ? "42\n{$v[2]}\n" : '');
        return $text."0\nSEQEND\n8\n0\n";
    }

    private function lwpolyline(array $vertices, bool $closed): string
    {
        $text = "0\nLWPOLYLINE\n8\n0\n90\n".count($vertices)."\n70\n".($closed ? 1 : 0)."\n";
        foreach ($vertices as $v) $text .= "10\n{$v[0]}\n20\n{$v[1]}\n".(isset($v[2]) && $v[2] ? "42\n{$v[2]}\n" : '');
        return $text;
    }
}
