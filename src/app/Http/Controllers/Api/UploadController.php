<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPhotoRequest;
use App\Models\Toilet;
use App\Models\ToiletPhoto;
use App\Services\GooglePlacesService;
use App\Services\MailService;
use App\Services\PlaceToiletService;
use App\Services\S3PhotoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Imagick;
use RuntimeException;

class UploadController extends Controller
{
    public function __construct(
        private S3PhotoStorageService $s3,
        private GooglePlacesService $placesService,
        private MailService $mail,
    ) {}

    /**
     * Upload a photo for a toilet.
     *
     * If `toilet_id` is provided, the photo is added to the existing toilet.
     * Otherwise a new placeholder toilet is created and the photo is attached to it.
     * HEIC images are converted to JPEG. A thumbnail is generated and both files are stored on S3.
     * Optional `exif` and `fixed_geo` parameters help derive GPS coordinates.
     */
    public function uploadFile(UploadPhotoRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $toiletExisted = false;
        $hasGeo = false;
        $placeId = '';

        if ($request->has('toilet_id')) {
            $toiletId = (int) $request->input('toilet_id');
            $toilet = Toilet::with('properties')->find($toiletId);

            if (! $toilet) {
                return response()->json(['success' => false, 'message' => 'Toilet not found'], 404);
            }

            $toiletExisted = true;
            $hasPlaceId = ! empty($toilet->place_id);

            if ($toilet->status !== 'hidden') {
                $this->notifyNewPhotosForExisting($toilet);
            }
        } else {
            $toilet = Toilet::create([
                'name' => 'Toilette',
                'owner' => 'unknown',
                'status' => 'hidden',
                'source' => 'photo_upload',
            ]);
            $toiletId = $toilet->id;
            $hasPlaceId = false;
        }

        $originalExtension = strtolower($file->getClientOriginalExtension());
        $isHeic = $originalExtension === 'heic';

        $fileTemp = tempnam(sys_get_temp_dir(), 'upload');
        $file->move(dirname($fileTemp), basename($fileTemp));

        if ($isHeic) {
            $this->convertHeicToJpeg($fileTemp);
        }

        $extension = $isHeic ? 'jpg' : $originalExtension;
        $filename = uniqid().'.'.$extension;
        $filenameThumb = uniqid().'.thumb.'.$extension;

        $exifData = $this->extractExif($request, $fileTemp);

        $imagePathThumb = tempnam(sys_get_temp_dir(), 'thumb');
        $this->createThumbnail($fileTemp, $imagePathThumb, $exifData);

        $placeResult = $this->handleGeoData($request, $exifData, $toilet, $toiletExisted, $hasPlaceId);
        $hasGeo = $placeResult['hasGeo'];
        $placeId = $placeResult['placeId'];

        $this->s3->put($toiletId, $filename, file_get_contents($fileTemp));
        $this->s3->put($toiletId, $filenameThumb, file_get_contents($imagePathThumb));

        unlink($fileTemp);
        unlink($imagePathThumb);

        ToiletPhoto::create([
            'fk_toiletId' => $toiletId,
            'filename' => $filename,
            'filename_thumb' => $filenameThumb,
            'exif' => $exifData,
        ]);

        return response()->json([
            'success' => true,
            'hasGeo' => $hasGeo,
            'placeId' => $placeId,
            'imageUrl' => config('wcinfo.s3.public_url').S3PhotoStorageService::pathForId($toiletId).'/'.$filename,
            'filename' => $filename,
            'toiletId' => $toiletId,
        ]);
    }

    /**
     * Submit photos and make the toilet visible.
     *
     * Changes the toilet type from `none` to `forall` and sends a notification email.
     */
    public function submitPhotos(int $toiletId): JsonResponse
    {
        $toilet = Toilet::findOrFail($toiletId);
        $toilet->update(['status' => 'active']);

        $this->mail->send(
            'hallo@wc-info.de',
            'Toilette mit Fotos hinzugefügt - ID: '.$toiletId,
            'https://wc-info.de/Toilets/Place---'.$toilet->place_id.'/Toilette---'.$toiletId
        );

        return response()->json(['success' => true]);
    }

    /**
     * Delete a photo for a toilet.
     *
     * If soft=true (default), renames the file and thumbnail on S3 with "_DELETED_" prefix,
     * sets deleted_ts and email_sent = 2 on the database record.
     * If soft=false, removes the file and thumbnail from S3 and deletes the database record.
     */
    public function deletePhoto(int $toiletId, string $filename, Request $request): JsonResponse
    {
        $photo = ToiletPhoto::where('fk_toiletId', $toiletId)
            ->where(function ($query) use ($filename) {
                $query->where('filename', $filename)
                    ->orWhere('filename', '_DELETED_'.$filename);
            })
            ->first();

        if (! $photo) {
            return response()->json(['success' => false, 'message' => 'Photo not found'], 404);
        }

        $soft = $request->boolean('soft', true);

        if ($soft) {
            $baseFilename = str_starts_with($photo->filename, '_DELETED_') ? substr($photo->filename, 9) : $photo->filename;
            $newFilename = '_DELETED_'.$baseFilename;

            if ($this->s3->exists($toiletId, $photo->filename) && $photo->filename !== $newFilename) {
                $this->s3->rename($toiletId, $photo->filename, $newFilename);
            }

            $newThumb = null;
            if (! empty($photo->filename_thumb)) {
                $baseThumb = str_starts_with($photo->filename_thumb, '_DELETED_') ? substr($photo->filename_thumb, 9) : $photo->filename_thumb;
                $newThumb = '_DELETED_'.$baseThumb;

                if ($this->s3->exists($toiletId, $photo->filename_thumb) && $photo->filename_thumb !== $newThumb) {
                    $this->s3->rename($toiletId, $photo->filename_thumb, $newThumb);
                }
            }

            $photo->update([
                'filename' => $newFilename,
                'filename_thumb' => $newThumb ?? $photo->filename_thumb,
                'deleted_ts' => now(),
                'email_sent' => 2,
            ]);

            return response()->json([
                'success' => true,
                'soft' => true,
                'filename' => $newFilename,
                'deleted_ts' => $photo->deleted_ts,
            ]);
        }

        // Hard delete
        $deletedCount = 0;

        if ($this->s3->exists($toiletId, $photo->filename)) {
            $this->s3->delete($toiletId, $photo->filename);
            $deletedCount++;
        }

        if (! empty($photo->filename_thumb) && $this->s3->exists($toiletId, $photo->filename_thumb)) {
            $this->s3->delete($toiletId, $photo->filename_thumb);
            $deletedCount++;
        }

        $photo->delete();

        return response()->json([
            'success' => true,
            'soft' => false,
            'filename' => $photo->filename,
            'deletedCount' => $deletedCount,
        ]);
    }

    private function notifyNewPhotosForExisting(Toilet $toilet): void
    {
        try {
            $this->mail->send(
                'hallo@wc-info.de',
                'Fotos zu existierender Toilette hinzugefügt - ID: '.$toilet->id,
                'https://wc-info.de/Toilets/Place---'.$toilet->place_id.'/Toilette---'.$toilet->id
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send upload notification email', ['toilet_id' => $toilet->id, 'error' => $e->getMessage()]);
        }
    }

    private function convertHeicToJpeg(string $fileTemp): void
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is required for HEIC support');
        }

        $image = new Imagick($fileTemp);
        $image->setImageFormat('jpeg');
        $image->writeImage($fileTemp);
        $image->destroy();
    }

    private function extractExif(Request $request, string $fileTemp): ?array
    {
        if ($request->has('exif')) {
            try {
                $exif = json_decode($request->input('exif'), true);
                if (is_array($exif) && isset($exif['GPS'])) {
                    return $exif;
                }
            } catch (\Throwable $e) {
                // fall through to exif_read_data
            }
        }

        if (function_exists('exif_read_data')) {
            return exif_read_data($fileTemp, 'EXIF', true) ?: null;
        }

        return null;
    }

    private function createThumbnail(string $sourcePath, string $thumbPath, ?array $exifData): void
    {
        $img = imagecreatefromjpeg($sourcePath);
        if (! $img) {
            return;
        }

        [$width, $height] = getimagesize($sourcePath);

        if ($width > $height) {
            $newWidth = 160;
            $newHeight = (int) floor($height * (160 / $width));
        } else {
            $newHeight = 160;
            $newWidth = (int) floor($width * (160 / $height));
        }

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $orientation = $exifData['IFD0']['Orientation'] ?? $exifData['THUMBNAIL']['Orientation'] ?? 0;
        $thumb = $this->applyExifOrientation($thumb, (int) $orientation);

        imagejpeg($thumb, $thumbPath);
        imagedestroy($img);
        imagedestroy($thumb);
    }

    private function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $rotation = 0;
        $mirror = false;

        switch ($orientation) {
            case 2:
                $mirror = true;
                break;
            case 3:
                $rotation = 180;
                break;
            case 4:
                $rotation = 180;
                $mirror = true;
                break;
            case 5:
                $rotation = 270;
                $mirror = true;
                break;
            case 6:
                $rotation = 270;
                break;
            case 7:
                $rotation = 90;
                $mirror = true;
                break;
            case 8:
                $rotation = 90;
                break;
        }

        if ($rotation) {
            $image = imagerotate($image, $rotation, 0);
        }

        if ($mirror) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    private function handleGeoData(Request $request, ?array $exifData, Toilet $toilet, bool $toiletExisted, bool $hasPlaceId): array
    {
        $hasGeo = false;
        $placeId = '';

        $hasGps = (isset($exifData['GPS']) && (isset($exifData['GPS']['GPSLatitude']) || isset($exifData['GPS']['Latitude'])))
            || $request->has('fixed_geo');

        if (! $hasGps) {
            return ['hasGeo' => false, 'placeId' => ''];
        }

        if ($hasPlaceId) {
            return ['hasGeo' => true, 'placeId' => $toilet->place_id];
        }

        if ($toiletExisted) {
            return ['hasGeo' => false, 'placeId' => ''];
        }

        if ($request->has('fixed_geo') && (! isset($exifData['GPS']) || ! isset($exifData['GPS']['GPSLatitude']))) {
            $fixed = json_decode($request->input('fixed_geo'), true);
            $latitude = $fixed['lat'];
            $longitude = $fixed['lon'];
        } elseif (isset($exifData['GPS']['Latitude']) && isset($exifData['GPS']['Longitude'])) {
            $latitude = $exifData['GPS']['Latitude'];
            $longitude = $exifData['GPS']['Longitude'];
        } else {
            $latitude = $this->gpsToDecimal($exifData['GPS']['GPSLatitude'], $exifData['GPS']['GPSLatitudeRef']);
            $longitude = $this->gpsToDecimal($exifData['GPS']['GPSLongitude'], $exifData['GPS']['GPSLongitudeRef']);
        }

        $places = $this->placesService->placesForCoordinates($latitude, $longitude);

        if (! is_array($places) || count($places) === 0 || empty($places[0]['place_id'])) {
            return ['hasGeo' => false, 'placeId' => ''];
        }

        $hasGeo = true;
        $placeId = $places[0]['place_id'];
        $placeDetails = $this->placesService->fetchPlaceDetails($placeId);

        $owner = $placeDetails['displayName']['text'] ?? (is_string($placeDetails['displayName'] ?? null) ? $placeDetails['displayName'] : null) ?? $placeDetails['name'] ?? $toilet->owner;
        $toilet->update([
            'owner' => $owner,
            'lat' => $latitude,
            'lon' => $longitude,
            'place_id' => $placeId,
        ]);

        $rawPeriods = $placeDetails['regularOpeningHours']['periods'] ?? $placeDetails['opening_hours']['periods'] ?? null;
        if (is_array($rawPeriods)) {
            $periods = PlaceToiletService::normalizePeriodPoints($rawPeriods);
            DB::table('toilet_properties')->insert([
                'fk_toiletId' => $toilet->id,
                'type' => 'place_opening_hours',
                'value' => json_encode($periods),
            ]);
        }

        $website = $placeDetails['websiteUri'] ?? $placeDetails['website'] ?? null;
        if ($website) {
            DB::table('toilet_properties')->insert([
                'fk_toiletId' => $toilet->id,
                'type' => 'website',
                'value' => $website,
            ]);
        }

        $address = $placeDetails['formattedAddress'] ?? $placeDetails['formatted_address'] ?? null;
        if ($address) {
            DB::table('toilet_properties')->insert([
                'fk_toiletId' => $toilet->id,
                'type' => 'address',
                'value' => $address,
            ]);
        }

        return ['hasGeo' => $hasGeo, 'placeId' => $placeId];
    }

    private function gpsToDecimal(array|string $coord, string $hemi): float
    {
        if (is_numeric($coord)) {
            return (float) $coord;
        }

        $degrees = count($coord) > 0 ? $this->gpsPartToDecimal($coord[0]) : 0;
        $minutes = count($coord) > 1 ? $this->gpsPartToDecimal($coord[1]) : 0;
        $seconds = count($coord) > 2 ? $this->gpsPartToDecimal($coord[2]) : 0;

        $flip = ($hemi === 'W' || $hemi === 'S') ? -1 : 1;

        return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
    }

    private function gpsPartToDecimal(string $part): float
    {
        $parts = explode('/', $part);

        if (count($parts) === 1) {
            return (float) $parts[0];
        }

        return (float) $parts[0] / (float) $parts[1];
    }
}
