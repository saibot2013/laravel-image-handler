<?php

namespace Amostajo\LaravelImageHandler\Classes;

/**
 * Image Handler provides methods for easy image handling. 
 *
 * @author Alejandro Mostajo
 * @license MIT
 * @package Amostajo\LaravelImageHandler
 */

use File;
use Eventviva\ImageResize;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Log;

class ImageHandler
{
	/**
	 * Laravel application
	 *
	 * @var \Illuminate\Foundation\Application
	 */
	public $app;

	/**
	 * Create a new confide instance.
	 *
	 * @param \Illuminate\Foundation\Application $app
	 *
	 * @return void
	 */
	public function __construct($app)
	{
		$this->app = $app;

		$path = public_path() . Config::get('image.thumbs_folder');

		if (!File::isWritable($path))
		{
			File::makeDirectory($path);
		}
	}

	/**
	 * Returns a thumb url based on the url an size provided.
	 *
	 * @param string $url 	 Base url.
	 * @param int    $width  Returned image width.
	 * @param int    $height Returned image height.
	 *
	 * @return string
	 */
	public static function thumb($url, $width = 0, $height = 0, $watermark = false)
	{
		if(empty($width) || !is_numeric($width)){
			$width = null;
		}
		if(empty($height) || !is_numeric($height)){
			$height = null;
		}
		if (is_null($height) && is_null($width)){
			// allow $width or $height to be empty
			$width = config('image.thumbs_width');
			$height = config('image.thumbs_height');
		} 
		
		$url = URL::asset($url);

		$cacheKey = preg_replace(
			[
				'/:hash/',
				'/:width/',
				'/:height/',
				'/:watermark/'
			], 
			[
				md5($url),
				$width,
				$height,
				$watermark
			], 
			config('image.cache_key_format')
		);
		Log::info("Requesting image for " . $cacheKey);

		return Cache::remember($cacheKey, config('image.cache_minutes'), function () use($url, $width, $height, $watermark, $cacheKey) {
			Log::info("Generating image for " . $cacheKey . ". Caching it for " . config("image.cache_minutes") . " minutes.");

			// Use error image if file cannot be found
			if(!ImageHandler::urlExists($url)){
				$url = config('image.url_not_found');
			}

			$info = pathinfo($url);
			if (!isset($info['extension'])) {
				$uniqid = explode('&', $info['filename']);
				$info['filename'] = $uniqid[count($uniqid) - 1];
				$info['extension'] = 'jpg';
			}
			$info['extension'] = explode('?', $info['extension'])[0];

			$assetPath = sprintf(
					'%s%s_%s_%sx%s%s.%s',
					Config::get('image.thumbs_folder'),
					md5($url),
					$info['filename'],
					$width,
					$height,
					($watermark) ? "_watermarked" : "",
					implode(array_slice(explode('?' , $info['extension']), 0, 1), "")
			);

			$size = @getimagesize($url);
			if($width == null && $size[1] != 0){
				$width = $height * ($size[0] / $size[1]);
			}
			if($height == null && $size[0] != 0){
				$height = $width * ($size[1] / $size[0]);
			}
			if (!file_exists(public_path() . $assetPath)) {
				
				// Process image
				if(@!is_array($size)){
					// is not an image...
					if($url != config('image.url_not_found') && $url != URL::asset(config('image.url_not_found'))){
						return self::thumb(URL::asset(config('image.url_not_found')), $width, $height);
					}else{
						return "image-not-found:". $url;
					}
				}

				$image = new ImageResize($url);
				// Resize to fit wanted width is too small
				if ($size[0] < $width) {

					$scaledPath = sprintf(
						'%s%s_%sx%s.%s',
						Config::get('image.thumbs_folder'),
						$info['filename'],
						$width,
						($watermark) ? "_watermarked" : "",
						implode(array_slice(explode('?' , $info['extension']), 0, 1), "")
					);

					$image->interlace = 1;

					$image->scale(ceil(100 + ((($width - $size[0]) / $size[0]) * 100)));

					$image->save(public_path() . $scaledPath);

					$image = new ImageResize(URL::asset($scaledPath));

					$size = getimagesize(URL::asset($scaledPath));
				}

				// Resize to fit wanted height is too small
				if ($size[1] < $height) {

					$scaledPath = sprintf(
						'%s%s_x%s%s.%s',
						Config::get('image.thumbs_folder'),
						$info['filename'],
						$height,
						($watermark) ? "_watermarked" : "",
						implode(array_slice(explode('?' , $info['extension']), 0, 1), "")
					);

					$image->interlace = 1;

					$image->scale(ceil(100 + ((($height - $size[1]) / $size[1]) * 100)));

					$image->save(public_path() . $scaledPath);

					$image = new ImageResize(URL::asset($scaledPath));
				}

				// Final crop
				$image->crop($width, $height);

				$image->save(public_path() . $assetPath);

				if($watermark){
					ImageHandler::applyWatermark(public_path() . $assetPath, public_path() . Config::get('image.watermark_file'), public_path() . $assetPath);
				}
			}

			return URL::asset($assetPath);

		});
	}

	/**
	 * Returns a resized image url.
	 * Resized on width constraint.
	 *
	 * @param string $url   Base url.
	 * @param int    $width Width to resize to.
	 *
	 * @return string
	 */
	public static function width($url, $width = 0, $watermark = false)
	{
		return ImageHandler::thumb($url, $width, null, $watermark);
	}

	/**
	 * Returns a resized image url.
	 * Resized on height constraint.
	 *
	 * @param string $url    Base url.
	 * @param int    $height Height to resize to.
	 *
	 * @return string
	 */
	public static function height($url, $height = 0, $watermark = false)
	{
		return ImageHandler::thumb($url, null, $height, $watermark);
	}

	private static function urlExists($url){
		if($url == "" || $url == NULL || $url == "/"){
			return false;
		}
		if (@fopen($url, "r")) {
			return true;
		}else{
			return false;
		}
	}

	protected static function applyWatermark($imageFile, $watermarkFile, $watermarkedImageFile)
    {
        $imagine = new Imagine;
        $watermark     = $imagine->open($watermarkFile);
        $watermarkSize = $watermark->getSize();
        $image     = $imagine->open($imageFile);
        $imageSize = $image->getSize();
        $desiredWatermarkWidth = $imageSize->getWidth() / 5;
        if($desiredWatermarkWidth < 1) $desiredWatermarkWidth = 1;
        if ($watermarkSize->getWidth() > $desiredWatermarkWidth) {
            $desiredWatermarkHeight = 57 / 246 * $desiredWatermarkWidth;
            if($desiredWatermarkHeight < 1) $desiredWatermarkHeight = 1;
            $watermark = $watermark->thumbnail(new Box($desiredWatermarkWidth, $desiredWatermarkHeight));
            $watermarkSize = $watermark->getSize();
        }
        $x = 0; //round(($imageSize->getWidth()  - $watermarkSize->getWidth()) / 2);
        $y = round($imageSize->getHeight() - $watermarkSize->getHeight()) - round($imageSize->getHeight()/10);
        if($imageSize->getHeight() - $y > $watermarkSize->getHeight() * 2){
        	$y = $imageSize->getHeight() - $watermarkSize->getHeight() * 2;
        }
        $center = new Point($x, $y);
        $image->paste($watermark, $center);
        $image->save($watermarkedImageFile);
    }
}
