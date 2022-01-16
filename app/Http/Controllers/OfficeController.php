<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Office;
use App\Models\Reservation;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\OfficeResource;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Validators\OfficeValidator;
use App\Notifications\OfficePendingApproval;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class OfficeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $offices =  Office::query()
            ->when(request('user_id') && auth()->user() && request('user_id') == auth()->id(), 
                fn($builder) => $builder, 
                fn($builder) => $builder->where('approval_status', Office::APPROVAL_APPROVED)->where('hidden', false) 
            )
            ->when( request('user_id'), fn ($builder) => $builder->whereUserId(request('user_id')))
            ->when( request('visitor_id'), 
                fn (Builder $builder) 
                => $builder->whereRelation('reservations', 'user_id', '=', request('visitor_id'))
            )
            ->when(
                request('lat') && request('lng'),
                fn ($builder) => $builder->nearestTo(request('lat'), request('lng')),
                fn ($builder) => $builder->orderBy('id', 'ASC')
            )
            ->latest('id')
            ->withCount(['reservations' => fn ($builder) => $builder->where('status', Reservation::STATUS_ACTIVE)])
            ->when(request('tags'),
                fn ($builder) => $builder->whereHas(
                    'tags', 
                    fn ($builder) => $builder->whereIn('id', request('tags')),
                    '=',
                    count(request('tags'))
                ) 
            )
            ->with(['images', 'tags', 'user'])
            ->paginate(20);

    	return OfficeResource::collection( $offices );
    }

    public function show(Office $office){
        $office->loadCount([ 'reservations' => fn ($builder) => $builder->where('status', Reservation::STATUS_ACTIVE)])
        ->load(['images', 'tags', 'user']);

    	return OfficeResource::make( $office );
    }

    public function create(): JsonResource
    {
        if(!auth()->user()->tokenCan('office.create')){
            abort(Response::HTTP_FORBIDDEN);
        }
        
        $attributes = (new OfficeValidator())->validate(
            $office = new Office(), 
            request()->all()
        );        

        $attributes['user_id'] = auth()->id();
        $attributes['approval_status'] = Office::APPROVAL_PENDING;

        $office = DB::transaction(function () use($office, $attributes) {
            $office->fill(
                Arr::except($attributes, ['tags'])
            )->save();
            $office->tags()->attach($attributes['tags']);
            return $office;
        });

        Notification::send(User::where('is_admin', true)->get(), new OfficePendingApproval($office));

    	return OfficeResource::make(
            $office->load(['images', 'tags', 'user'])
        );
    }

    public function update(Office $office)
    {
        if(!auth()->user()->tokenCan('office.create')){
            abort(Response::HTTP_FORBIDDEN);
        }
        $this->authorize('update', $office);
        $attributes = (new OfficeValidator())->validate($office, request()->all());
        $office->fill(Arr::except($attributes, ['tags']));

        if( $requiresReview = $office->isDirty([ 'lat', 'lng', 'price_per_day' ]) ) {
            $office->fill([ 'approval_status' => Office::APPROVAL_PENDING ]);
        }

        DB::transaction(function () use($office, $attributes) {
            $office->save();
            if(isset($attributes['tags'])) {
                $office->tags()->sync($attributes['tags']);
            }
        });

        if($requiresReview) {
            Notification::send(User::where('is_admin', true)->get(), new OfficePendingApproval($office));
        }
        return OfficeResource::make(
            $office->load(['images', 'tags', 'user'])
        );
    }

    public function delete(Office $office)
    {
    	abort_unless(auth()->user()->tokenCan('office.delete'),
            Response::HTTP_FORBIDDEN
        );
        $this->authorize('delete', $office);
        if($office->reservations()->where('status', Reservation::STATUS_ACTIVE)->exists()) {
            throw ValidationException::withMessages(['office' => 'Cannot delete this office']);
        }
        $office->images()->each(function($image) {
            Storage::delete($image->path);
            $image->delete();
        });
        $office->delete();

    }

}

