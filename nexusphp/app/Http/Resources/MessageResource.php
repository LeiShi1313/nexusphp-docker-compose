<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'msg' => strip_all_tags($this->msg),
            'added_human' => $this->added->diffForHumans(),
            'send_user' => new UserResource($this->whenLoaded('send_user')),
        ];
    }
}
