<?php

class TrackHistoryCtrl extends BaseController {

    public function getTracks()
    {
        $user_id = Input::get('user_id');
        $page = Input::get('page');

        $user = User::find($user_id);
        if (!$user) {
            throw new InvalidInputException('Несуществующий user_id');
        }

        DB::getPaginator()->setCurrentPage($page);

        $result = Track::whereHas('history', function($q) use ($user_id) {
            $q->whereUserId($user_id)->distinct();
        })
            ->join('track_history', function($join) use ($user_id) {
                  $join->on('track_history.track_id', '=', 'tracks.id')
                       ->where('track_history.user_id', '=', $user_id );
                }) 
            ->orderBy('track_history.listen_date', 'desc')
            ->with(['project', 'tags', 'album', 'history' => function($q) use ($user_id) {
                $q->where('user_id', '=', $user_id)->orderBy('listen_date', 'desc');
                }])
            ->select('tracks.*')
            ->distinct()
            ->paginate(20);

        return $result;
    }

    public function postTracks()
    {
        $track_id = Input::get('track_id');
        $user_id  = Input::get('user_id');

        $user = User::find($user_id);
        $track = Track::find($track_id);

        $inPromo = Promo::where('trackId', $track_id)
            ->where('from', '<=', date('Y-m-d'))
            ->where('to', '>=', date('Y-m-d'))
            ->orWhere(function($query) use ($track_id)
            {
                $query->where('trackId', $track_id)
                    ->where('type', '=', 'personal');
            })
            ->orderBy('type','asc')->first();

        if (!$user || !$track)
            throw new InvalidInputException('Несуществующий user_id или track_id');

       $model = TrackHistory::where('track_id', $track_id)->where('user_id', $user_id)->first();
        if (!$model) {
            $model = new TrackHistory();
            $model->track_id = $track_id;
            $model->user_id  = $user_id;
        }

        $model = new TrackHistory();
        $model->track_id = $track_id;
        $model->user_id  = $user_id;
        $model->promo_id = $inPromo ? $inPromo->id : null;

        $model->listen_date = Carbon::now('Europe/Moscow')->toDateTimeString();
        $model->listen_count += 1;
        $model->save();

        TrackHistory::deleteLast($user_id);

        return $model;
    }

}
