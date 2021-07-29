<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\Torrent;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

class TorrentResource extends JsonResource
{
    protected $imageTypes = ['image', 'attachment'];

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $out = [
            'id' => $this->id,
            'name' => $this->name,
            'filename' => $this->filename,
            'small_descr' => $this->small_descr,
            'comments' => $this->comments,
            'size_human' => mksize($this->size),
            'added' => $this->added->toDateTimeString(),
            'added_human' => $this->added->format('Y-m-d H:i'),
            'ttl' => $this->added->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]),
            'leechers' => $this->leechers,
            'seeders' => $this->seeders,
            'times_completed' => $this->times_completed,
            'numfiles' => $this->numfiles,
            'user' => new UserResource($this->whenLoaded('user')),
            'basic_category' => new CategoryResource($this->whenLoaded('basic_category')),
        ];

        if ($request->routeIs('torrents.show')) {
            $baseInfo = [
                ['label' => nexus_trans('torrent.show.size'), 'value' => mksize($this->size)],
            ];
            foreach (Torrent::getBasicInfo() as $relation => $text) {
                if ($info = $this->whenLoaded($relation)) {
                    $baseInfo[] = ['label' => $text, 'value' => $info->name];
                }
            }
            $out['base_info'] = $baseInfo;
            $descriptionArr = format_description($this->descr);
            $out['description'] = $descriptionArr;

            $out['images'] = get_image_from_description($descriptionArr);

            $out['thank_users_count'] = $this->thank_users_count;
            $out['peers_count'] = $this->peers_count;
        }

        $out['cover'] = get_image_from_description(format_description($this->descr), true);
//            $out['upload_peers_count'] = $this->upload_peers_count;
//            $out['download_peers_count'] = $this->download_peers_count;
//            $out['finish_peers_count'] = $this->finish_peers_count;

        return $out;

    }



}
