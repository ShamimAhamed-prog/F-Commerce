<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** The web app manifest, generated so every URL follows APP_BASE_PATH (config/deployment.php). */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $b = config('deployment.base_path');
        $icon = fn (string $file, string $size, string $purpose = null) => array_filter([
            'src' => "$b/icons/$file", 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose,
        ]);
        $shot = fn (string $file, string $size, string $form, string $label) => [
            'src' => "$b/icons/$file", 'sizes' => $size, 'type' => 'image/png', 'form_factor' => $form, 'label' => $label,
        ];

        return response()->json([
            'id' => "$b/app/",
            'name' => 'F-Commerce',
            'short_name' => 'F-Commerce',
            'description' => 'Orders, inventory, couriers and accounts for Facebook and online sellers in Bangladesh.',
            'start_url' => "$b/app/dashboard?source=pwa",
            'scope' => "$b/",
            'display' => 'standalone',
            'display_override' => ['standalone'],
            'orientation' => 'any',
            'theme_color' => '#5b5bf0',
            'background_color' => '#f3f5fb',
            'lang' => 'en',
            'dir' => 'ltr',
            'categories' => ['business', 'productivity'],
            'icons' => [
                $icon('icon-192.png', '192x192', 'any'),
                $icon('icon-512.png', '512x512', 'any'),
                $icon('icon-maskable-512.png', '512x512', 'maskable'),
                $icon('icon-monochrome-512.png', '512x512', 'monochrome'),
            ],
            'shortcuts' => [[
                'name' => 'Dashboard', 'url' => "$b/app/dashboard?source=shortcut",
                'icons' => [['src' => "$b/icons/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png']],
            ]],
            'screenshots' => [
                $shot('screenshot-wide.png', '1280x720', 'wide', 'Dashboard on desktop'),
                $shot('screenshot-narrow.png', '720x1280', 'narrow', 'Dashboard on mobile'),
            ],
        ])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'public, max-age=3600');
    }
}
