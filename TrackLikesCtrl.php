<?php

class TrackLikesCtrl extends BaseController
{

    public function postLike()
    {
        if (Auth::check()) {
            $track_id = Input::get('track_id');
            $user_id = Auth::id();
            $promo_id = Input::get('promo_id');

            $inPromo = Promo::where('trackId', $track_id)
                ->where('from', '<=', date('Y-m-d'))
                ->where('to', '>=', date('Y-m-d'))
                ->orWhere(function($query) use ($track_id)
                {
                    $query->where('trackId', $track_id)
                        ->where('type', '=', 'personal');
                })->orderBy('type','asc')->first();


            $track = Track::find($track_id);
            if (!$track) {
                throw new InvalidInputException;
            }

            $is_user_track = User::query()
                ->join('projects', 'users.id', '=', 'projects.creatorId')
                ->join('tracks', 'projects.id', '=', 'tracks.project_id')
                ->where('users.id', '=', $user_id)
                ->where('tracks.id', '=', $track_id)->first();

            if ($is_user_track) {
                throw new UserException('Нельзя лайкнуть свой трек');
            }

            if (Auth::user()->can('like_create')) {
                $like = TrackLikes::firstOrNew([
                    'user_id' => $user_id,
                    'track_id' => $track_id,
                    'promo_id' =>  $inPromo ? $inPromo->id : null
                ]);
                DB::transaction(function () use (&$user_id, &$track_id, &$track, &$like, $inPromo) {


                    if (!$like->exists) {
                        $like->user_id = $user_id;
                        $like->track_id = $track_id;
                        $like->promo_id =  $inPromo ? $inPromo->id : null;
                    }
                    $like->liked = ($like->liked == 0) ? 1 : 0;

                    $now_minus_day = new DateTime('now');
                    $now_minus_day->modify('-1 day');
                    $server_date = new DateTime($like->date_liked);
                    if ($server_date < $now_minus_day) {
                        $like->date_liked = Carbon::now('Europe/Moscow')->toDateTimeString();
                    }

                    if (!$like->exists) {
                        $like->date_liked = Carbon::now('Europe/Moscow')->toDateTimeString();
                    }

                    if (Auth::user()->can('like_create_verified')) {
                        $like->is_verified = true;
                    }

                    $like->save();

                    if ($like->liked == 1) {
                        $track->count_liked += 1;
                    } else {
                        $track->count_liked -= 1;
                    }

                    $track->save();
                });
                return json_encode(Track::find($track->id), JSON_NUMERIC_CHECK);
            } else {
                throw new UserException('Емейл не авторизирован');
            }

        } else {
            throw new UserException('Сначала нужно авторизироваться');
        }
    }

    public function getLikes()
    {
        $track_id = Input::get('track_id');
        $type_id = Input::get('type_id');
        $track = Track::find($track_id);
        if (!$track) {
            throw new InvalidInputException;
        }
        $likes['data'] = TrackLikes::likes($track_id, $type_id);
        $counters = TrackLikes::countByTypes($track_id);

        $temp = array();
        foreach ($counters as $count) {
            $temp[$count->type_id] = $count;
        }
        $ids = array();
        foreach ($likes['data'] as $like) {
            $ids[] = $like->id;
        }
        $users = User::select([
                        'id',
                        'name',
                        'infoShort',
                        'birthday_date',
                        'gender',
                        'login',
                        'avatar',
                        'coverSettings',
                        'city_id',
                		'country_id'])
        	->whereIn('id',$ids)
            ->with('projects','city','country')
            ->get();
        $likes['users'] = [];
        foreach ($users as $user) {
            $likes['users'][$user->id] = $user;
        }
        $likes['userTypes'] = $temp;

        return $likes;
    }

    public function getPeoplebytrack()
    {
        $track_id = Input::get('track_id');
        $people = TrackLikes::peopleByTrack($track_id);
        return $people;
    }

    public function getAllLikesByUserId($user_id)
    {
        return TrackLikes::whereUserId($user_id);
    }
}
