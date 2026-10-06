<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Support\RawbtReceipt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RawbtLogoTest extends TestCase
{
    public function test_logo_pixels_and_transparency_are_encoded_as_monochrome_raster(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to encode logos.');
        }
        Storage::fake('public');
        $image = imagecreatetruecolor(8, 1);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesetpixel($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        imagesetpixel($image, 7, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('branding/logo.png', ob_get_clean());
        imagedestroy($image);
        $store = new Store(['receipt_show_logo' => true, 'logo_path' => 'branding/logo.png']);
        $logo = RawbtReceipt::logo($store);
        $this->assertSame("\x1ba\x01\x1dv0\x00\x01\x00\x01\x00\x81\n\x1ba\x00", $logo);
        $uri = RawbtReceipt::uri([[['type' => 'text', 'text' => 'CUSTOMER']]], $logo);
        $bytes = base64_decode(explode('#', substr($uri, strlen('intent:base64,')))[0], true);
        $this->assertLessThan(strpos($bytes, 'CUSTOMER'), strpos($bytes, $logo));
        $store->receipt_show_logo = false;
        $this->assertNull(RawbtReceipt::logo($store));
    }

    public function test_missing_logo_reports_a_useful_error(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to encode logos.');
        }
        Storage::fake('public');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unggah ulang logo');
        RawbtReceipt::logo(new Store(['receipt_show_logo' => true, 'logo_path' => 'branding/missing.png']));
    }

    public function test_large_logo_is_resized_to_fit_a_small_intent_payload(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to encode logos.');
        }
        Storage::fake('public');
        $image = imagecreatetruecolor(512, 512);
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('branding/large.png', ob_get_clean());
        imagedestroy($image);
        $logo = RawbtReceipt::logo(new Store(['receipt_show_logo' => true, 'logo_path' => 'branding/large.png']));
        $this->assertStringStartsWith("\x1ba\x01\x1dv0\x00".pack('vv', 12, 96), $logo);
        $this->assertSame(12 * 96 + 15, strlen($logo));
    }
}
