<?php
/**
 * PDF rendering helpers (moved from simku-keuangan.php to improve maintainability).
 *
 * This file is part of WP SIMKU and is loaded from includes/bootstrap.php.
 */

if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_PDF {
        private function fmt_date_short($date) {
            $date = trim($date);
            if ($date === '') return '';
            // Accept YYYY-MM-DD or full datetime
            $ts = strtotime($date);
            if (!$ts) return '';
            return wp_date('d/m/Y', $ts);
        }

        private function pdf_clean_text($text) {
            // Normalize whitespace and avoid control chars that can break the PDF stream.
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
            $text = preg_replace("/\s+/u", " ", $text);
            return trim((string)$text);
        }

        private function pdf_strlen($text) {
            if (function_exists('mb_strlen')) return (int)mb_strlen($text, 'UTF-8');
            if ($text === '') return 0;
            return (int)preg_match_all('/./us', $text, $m);
        }

        private function pdf_substr($text, $start, $len) {
            if (function_exists('mb_substr')) return (string)mb_substr($text, $start, $len, 'UTF-8');
            if ($text === '' || $len <= 0) return '';
            preg_match_all('/./us', $text, $m);
            $chars = $m[0] ?? [];
            return implode('', array_slice($chars, $start, $len));
        }

        private function pdf_wrap_text($text, $max_chars) {
            $text = $this->pdf_clean_text($text);
            if ($text === '') return [''];

            $max_chars = max(4, $max_chars);
            $words = preg_split('/\s+/u', $text) ?: [];
            $lines = [];
            $line = '';

            foreach ($words as $w) {
                $w = (string)$w;
                if ($w === '') continue;

                // If a single "word" is longer than max, split it safely
                if ($this->pdf_strlen($w) > $max_chars) {
                    if ($line !== '') { $lines[] = $line; $line = ''; }
                    $pos = 0;
                    $wlen = $this->pdf_strlen($w);
                    while ($pos < $wlen) {
                        $chunk = $this->pdf_substr($w, $pos, $max_chars);
                        $lines[] = $chunk;
                        $pos += $max_chars;
                    }
                    continue;
                }

                $candidate = ($line === '') ? $w : ($line . ' ' . $w);
                if ($this->pdf_strlen($candidate) <= $max_chars) {
                    $line = $candidate;
                } else {
                    if ($line !== '') $lines[] = $line;
                    $line = $w;
                }
            }

            if ($line !== '') $lines[] = $line;
            if (empty($lines)) $lines = [''];
            return $lines;
        }

        private function pdf_table_grid_var_cmd($x, $y_top, $col_w, $row_h, $w = 0.8) {
            $s = "";
            $total_w = array_sum($col_w);
            $total_h = array_sum($row_h);
            $y_bottom = $y_top - $total_h;

            // Horizontal lines (top + each row boundary)
            $yy = $y_top;
            $s .= $this->pdf_line_cmd($x, $yy, $x + $total_w, $yy, $w);
            foreach ($row_h as $h) {
                $yy -= (float)$h;
                $s .= $this->pdf_line_cmd($x, $yy, $x + $total_w, $yy, $w);
            }

            // Vertical lines (full height)
            $xx = $x;
            $s .= $this->pdf_line_cmd($xx, $y_top, $xx, $y_bottom, $w);
            foreach ($col_w as $cw) {
                $xx += (float)$cw;
                $s .= $this->pdf_line_cmd($xx, $y_top, $xx, $y_bottom, $w);
            }

            return $s;
        }

        private function pdf_truncate_text($text, $max_chars) {
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($max_chars <= 0) return '';
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($text, 'UTF-8') <= $max_chars) return $text;
                return mb_substr($text, 0, max(0, $max_chars - 1), 'UTF-8').'…';
            }
            if (strlen($text) <= $max_chars) return $text;
            return substr($text, 0, max(0, $max_chars - 1)).'…';
        }

        private function pdf_escape_text($t) {
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
        }

        private function pdf_text_cmd($x, $y, $size, $text, $font = 'F1') {
            $text = $this->pdf_escape_text($text);
            $x = number_format($x, 2, '.', '');
            $y = number_format($y, 2, '.', '');
            $size = max(1, (int)$size);
            return "BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$text}) Tj ET\n";
        }

        /**
         * Render a text command with a gray (non-stroking) fill color.
         * Uses q/Q to isolate graphics state so it won't affect other text.
         *
         * @param float $gray 0.0 (black) .. 1.0 (white)
         */
        private function pdf_text_cmd_gray($x, $y, $size, $text, $font = 'F1', $gray = 0.35) {
            $gray = (float)$gray;
            if ($gray < 0.0) $gray = 0.0;
            if ($gray > 1.0) $gray = 1.0;
            $text = $this->pdf_escape_text($text);
            $x = number_format($x, 2, '.', '');
            $y = number_format($y, 2, '.', '');
            $size = max(1, (int)$size);
            $g = number_format($gray, 2, '.', '');
            return "q {$g} g BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$text}) Tj ET Q
";
        }



        private function pdf_line_cmd($x1, $y1, $x2, $y2, $w = 0.8) {
            $x1 = number_format($x1, 2, '.', '');
            $y1 = number_format($y1, 2, '.', '');
            $x2 = number_format($x2, 2, '.', '');
            $y2 = number_format($y2, 2, '.', '');
            $w = number_format(max(0.1, (float)$w), 2, '.', '');
            return "{$w} w {$x1} {$y1} m {$x2} {$y2} l S\n";
        }

        private function pdf_image_cmd($name, $x, $y, $w, $h) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$name);
            $x = number_format((float)$x, 2, '.', '');
            $y = number_format((float)$y, 2, '.', '');
            $w = number_format(max(0.1, (float)$w), 2, '.', '');
            $h = number_format(max(0.1, (float)$h), 2, '.', '');
            // Draw image XObject: q w 0 0 h x y cm /Name Do Q
            return "q {$w} 0 0 {$h} {$x} {$y} cm /{$name} Do Q\n";
        }


        private function pdf_table_grid_cmd($x, $y_top, $col_w, $row_h, $rows_count, $w = 0.8) {
            $x0 = (float)$x;
            $x1 = $x0 + array_sum($col_w);
            $w = number_format(max(0.1, (float)$w), 2, '.', '');
            $s = "{$w} w\n";

            // Horizontal lines
            for ($i = 0; $i <= $rows_count; $i++) {
                $yy = $y_top - ($i * $row_h);
                $s .= number_format($x0, 2, '.', '').' '.number_format($yy, 2, '.', '').' m '
                    .number_format($x1, 2, '.', '').' '.number_format($yy, 2, '.', '')." l S\n";
            }

            // Vertical lines
            $xx = $x0;
            $s .= number_format($xx, 2, '.', '').' '.number_format($y_top, 2, '.', '').' m '
                .number_format($xx, 2, '.', '').' '.number_format($y_top - ($rows_count * $row_h), 2, '.', '')." l S\n";

            foreach ($col_w as $cw) {
                $xx += (float)$cw;
                $s .= number_format($xx, 2, '.', '').' '.number_format($y_top, 2, '.', '').' m '
                    .number_format($xx, 2, '.', '').' '.number_format($y_top - ($rows_count * $row_h), 2, '.', '')." l S\n";
            }

            return $s;
        }

        private function simple_pdf_pages($page_streams, $fonts, $meta = []) {
            $n = count($page_streams);
            if ($n < 1) $page_streams = [""];

            // Object id layout:
            // 1: catalog
            // 2: pages
            // 3..(2n+2): page+content pairs
            // fonts start at (2n+3)
            $n = count($page_streams);
            $font_keys = array_keys($fonts);
            $meta = is_array($meta) ? $meta : [];
            $mediaboxes = isset($meta['mediaboxes']) && is_array($meta['mediaboxes']) ? $meta['mediaboxes'] : [];
            $images = isset($meta['images']) && is_array($meta['images']) ? $meta['images'] : [];
            $image_keys = array_keys($images);
            $font_ids = [];
            $first_font_id = 3 + (2 * $n);

            foreach ($font_keys as $i => $k) {
                $font_ids[$k] = $first_font_id + $i;
            }

            $first_img_id = $first_font_id + count($font_keys);
            $image_ids = [];
            foreach ($image_keys as $i => $name) {
                // sanitize name for PDF
                $safe = preg_replace('/[^A-Za-z0-9_]/', '', (string)$name);
                if ($safe === '') $safe = 'Im'.(string)$i;
                $image_ids[$safe] = $first_img_id + $i;
            }

            $objects = [];

            // 1 Catalog
            $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

            // 2 Pages (Kids filled later)
            $kids = [];
            for ($i = 0; $i < $n; $i++) {
                $page_id = 3 + ($i * 2);
                $kids[] = "{$page_id} 0 R";
            }
            $objects[2] = "<< /Type /Pages /Kids [ ".implode(' ', $kids)." ] /Count {$n} >>";

            // Page objects and content objects
            for ($i = 0; $i < $n; $i++) {
                $page_id = 3 + ($i * 2);
                $content_id = $page_id + 1;
                $stream = (string)$page_streams[$i];
                $len = strlen($stream);

                $font_dict_parts = [];
                foreach ($font_ids as $k => $fid) {
                    $font_dict_parts[] = "/{$k} {$fid} 0 R";
                }
                $font_dict = implode(' ', $font_dict_parts);

                $mb = $mediaboxes[$i] ?? [0,0,595,842];
                if (!is_array($mb) || count($mb) !== 4) { $mb = [0,0,595,842]; }
                $mb_str = implode(' ', array_map(function($v){ return (string)(float)$v; }, $mb));

                $xobj_dict = '';
                if (!empty($image_ids)) {
                    $parts = [];
                    foreach ($image_ids as $nm => $iid) { $parts[] = "/{$nm} {$iid} 0 R"; }
                    $xobj_dict = " /XObject << ".implode(' ', $parts)." >>";
                }

                $objects[$page_id] = "<< /Type /Page /Parent 2 0 R /MediaBox [{$mb_str}] /Resources << /Font << {$font_dict} >>{$xobj_dict} >> /Contents {$content_id} 0 R >>";
                $objects[$content_id] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
            }

            // Font objects
            foreach ($fonts as $k => $base) {
                $fid = $font_ids[$k];
                $objects[$fid] = "<< /Type /Font /Subtype /Type1 /Name /{$k} /BaseFont /{$base} >>";
            }

            // Image objects (JPEG only; PNG/WebP should be converted before passing here)
            foreach ($image_ids as $nm => $iid) {
                $src = $images[$nm] ?? null;
                // allow meta to provide images keyed by sanitized or original name
                if ($src === null) {
                    // try original key lookup
                    foreach ($images as $ok => $ov) {
                        $safe = preg_replace('/[^A-Za-z0-9_]/', '', (string)$ok);
                        if ($safe === $nm) { $src = $ov; break; }
                    }
                }
                if (!is_array($src)) continue;
                $data = $src['data'] ?? '';
                $wpx = (int)($src['w'] ?? 0);
                $hpx = (int)($src['h'] ?? 0);
                if (!is_string($data) || $data === '' || $wpx <= 0 || $hpx <= 0) continue;
                $len = strlen($data);
                $objects[$iid] = "<< /Type /XObject /Subtype /Image /Width {$wpx} /Height {$hpx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$data}\nendstream";
            }

            // Build final PDF with xref
            $pdf = "%PDF-1.4\n";
            $offsets = [0];

            $max_id = max(array_keys($objects));
            for ($i = 1; $i <= $max_id; $i++) {
                $offsets[$i] = strlen($pdf);
                $obj = $objects[$i] ?? '';
                $pdf .= "{$i} 0 obj\n{$obj}\nendobj\n";
            }

            $xref_offset = strlen($pdf);
            $pdf .= "xref\n0 ".($max_id + 1)."\n";
            $pdf .= "0000000000 65535 f \n";
            for ($i = 1; $i <= $max_id; $i++) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
            }

            $pdf .= "trailer\n<< /Size ".($max_id + 1)." /Root 1 0 R >>\nstartxref\n{$xref_offset}\n%%EOF";
            return $pdf;
        }

    private function simple_text_pdf($text) {
            // Minimal PDF (single page if possible) with Helvetica + Helvetica-Bold.
            // Supports a larger bold title on the first line.
            $lines = preg_split("/\r\n|\n|\r/", $text);
            $maxw = 92;
            $wrapped = [];
            foreach ($lines as $i => $l) {
                $l = (string)$l;
                // keep empty lines
                if ($l === '') { $wrapped[] = ''; continue; }
                while ($this->pdf_strlen($l) > $maxw) {
                    $wrapped[] = $this->pdf_substr($l, 0, $maxw);
                    $l = $this->pdf_substr($l, $maxw, max(0, $this->pdf_strlen($l) - $maxw));
                }
                $wrapped[] = $l;
            }

            $y = 810;
            $content = "BT\n";
            foreach ($wrapped as $idx => $l) {
                $l = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l);
                // Title styling for first non-empty line
                if ($idx === 0) {
                    $content .= "/F2 16 Tf\n";
                } elseif ($idx === 1 || $idx === 2) {
                    $content .= "/F1 10 Tf\n";
                } else {
                    $content .= "/F1 10 Tf\n";
                }
                $content .= sprintf("1 0 0 1 50 %d Tm (%s) Tj\n", $y, $l);
                $y -= 14;
                if ($y < 40) break; // keep single page for now
            }
            $content .= "ET\n";
            $len = strlen($content);

            $objects = [];
            $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
            $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
            $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /Contents 5 0 R >> endobj\n";
            $objects[] = "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
            $objects[] = "5 0 obj << /Length {$len} >> stream\n{$content}endstream endobj\n";
            $objects[] = "6 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj\n";

            $pdf = "%PDF-1.4\n";
            $xref = [];
            $offset = strlen($pdf);
            foreach ($objects as $obj) {
                $xref[] = $offset;
                $pdf .= $obj;
                $offset = strlen($pdf);
            }
            $xref_pos = $offset;
            $pdf .= "xref\n0 ".(count($xref)+1)."\n";
            $pdf .= "0000000000 65535 f \n";
            foreach ($xref as $o) {
                $pdf .= sprintf("%010d 00000 n \n", $o);
            }
            $pdf .= "trailer << /Size ".(count($xref)+1)." /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";
            return $pdf;
        }
            

    

}
