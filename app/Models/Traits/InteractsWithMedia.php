<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Media\Media;
use Spatie\Image\Image;
use Spatie\Image\Manipulations;

trait InteractsWithMedia
{
    public function getMedia(string $collectionName = 'default')
    {
        return $this->allegati()
            //->orderby('order_column')
            ->where('collection_name', $collectionName)
            ->get();
    }

    public function getMain(string $collectionName = 'default')
    {
        return $this->media()
            ->where(['collection_name'=>$collectionName, 'main'=>1])
            ->first();
    }

    public function addMediaFromRequest(\Illuminate\Http\UploadedFile $file, string $collection = 'default', string $group = null)
    {
        $file_original_name = $file->getClientOriginalName();
        $file_original_extension = $file->getClientOriginalExtension();
        $file_size = $file->getSize();
        $mime_type = $file->getClientMimeType();

        return $this->store($file, $collection, $group, $file_original_name, $file_original_extension, $file_size, $mime_type);
    }

    public function addMedia(\Illuminate\Http\File $file, string $collection = 'default', string $group = null)
    {
        $file_original_name = $file->getFileName();
        $file_original_extension = $file->getExtension();
        $file_size = $file->getSize();
        $mime_type =$file->getMimeType();

        return $this->store($file, $collection, $group, $file_original_name, $file_original_extension, $file_size, $mime_type);
    }
        
    public function store($file, $collection, $group, $file_original_name, $file_original_extension, $file_size, $mime_type, $principale = false)
    {
        $disk_name = config('cms.media.disk_name');

        if(empty($disk_name)) {
            abort(500, 'Disco salvataggio non specificato');
        }
        $file_base_name = Str::slug(basename($file_original_name, $file_original_extension));
        $file_name = strtolower($file_base_name.'.'.$file_original_extension);

        // 1. Creo l'elemento media e lo salvo. Lo devo salvare subito perchè mi serve l'id
        $media = new Media();
        $media->collection_name = $collection;
        $media->title = $file_base_name;
        $media->file_name = $file_name;
        $media->mime_type = $mime_type;
        $media->disk = $disk_name;
        $media->size = $file_size;
        $media->generated_conversions = [];
        $media->save();

        // 2. Carico il file
        $relative_path = $collection.'/'.$media->id;
        $full_file_name = $relative_path.'/'.$file_name;
        if(!Storage::disk($disk_name)->exists($relative_path)) {
            Storage::disk($disk_name)->makeDirectory($relative_path, 0755, true);
        }

        // 3. Se il caricamento è andato a buon fine genero le eventuali conversioni
        if(Storage::disk($disk_name)->putFileAs($relative_path, $file, $file_name))
        {
            if($media->isImage() && !$media->isSvg())
            {
                $image_url = Storage::disk($disk_name)->path($full_file_name);
                $image = Image::load($image_url);

                $media->width = $image->getWidth();
                $media->height = $image->getHeight();

                $conversions = Media::$conversions;
                if(is_array($conversions)) {
                    $conversions_path = $relative_path.'/conversions/';
                    if(!Storage::disk($disk_name)->exists($conversions_path)) {
                        Storage::disk($disk_name)->makeDirectory($conversions_path, 0755, true);
                    }
                    $generated_conversions = [];
                    foreach($conversions as $key=>$conversion) {
                        $extension = $file_original_extension;//'webp';
                        $conversion_file_name = Storage::disk($disk_name)->path($conversions_path.$file_base_name.'-'.$key.'.'.$extension);
                        foreach($conversion as $action=>$value) {
                            $image->{$action}($value);
                        }
                        $image->format(Manipulations::FORMAT_WEBP)
                            ->save($conversion_file_name);
                            
                        $generated_conversions[$key] = true;// $media->getUrl($key);
                    }
                    $media->generated_conversions = $generated_conversions;
                }
            }

            $media->save();

            $this->allegati()->save($allegato = $media->toAllegato($principale));

            $allegato->setHighestOrderNumber();

            return $allegato;
        }

        return null;
    }


    public function clearAllegatiCollection(string $collectionName = 'default')
    {
        return $this->allegati()
            ->when(!is_null($collectionName), function($query) use($collectionName) {
                $query->where('collection_name', $collectionName);
            })
            ->delete();     
    }
}
