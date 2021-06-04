<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExtendedCharacterResource extends JsonResource
{

    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'                         => $this->character_id,
            'slot'                       => $this->character_slot,
            'gender'                     => $this->gender,
            'firstName'                  => $this->first_name,
            'lastName'                   => $this->last_name,
            'name'                       => $this->name,
            'dateOfBirth'                => $this->date_of_birth->format('Y-m-d'),
            'cash'                       => $this->cash,
            'bank'                       => $this->bank,
            'money'                      => $this->money,
            'stocksBalance'              => $this->stocks_balance,
            'jobName'                    => $this->job_name,
            'departmentName'             => $this->department_name,
            'positionName'               => $this->position_name,
            'backstory'                  => $this->backstory,
            'vehicles'                   => VehicleResource::collection($this->vehicles),
            'characterDeleted'           => $this->character_deleted,
            'characterDeletionTimestamp' => $this->character_deletion_timestamp,
            'steamIdentifier'            => $this->steam_identifier,
            'player'                     => new PlayerResource($this->player),
        ];
    }

}