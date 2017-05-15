<?php

class ArticleCtrl extends BaseController
{
	protected function isOwner($instance){
		return Auth::check() && Auth::user()->id == $instance->author_id;
	}

    protected function hasProjectPerms($instance){

        if($instance->author_id == Auth::user()->id){
            return true;
        }

        $pu = ProjectUser::where('project_id', $instance->project_id)
                           ->where('user_id', Auth::user()->id)
                           ->first();

        if( isset($pu) ){
            return $pu->role->name == 'project_editor' || $pu->role->name == 'project_admin' 
                || $pu->role->name == 'project_moderator';
        } else {
            return false;
        }
    }

	public function show($id)
	{
		return $this->getInstance($id);
	}

	public function mainBlog($type, $id) {
		$params = [
			'main_blog' => 1
		];
		if($type == 'project'){
			$params['project_id'] = $id;
		}
		elseif($type == 'user'){
			$params['author_id'] = $id;
		}
		$id = Article::where($params)->pluck('id');
		return $id ?  $this->getInstance($id) : null;
	}

	protected function updateSectionsTags($sections){
		$section_ids = array_pluck($sections, 'id');
		// Узнаем статистику использования тегов для выбранных разделов
		$counts = DB::table('article_section')
				->whereIn('article_section.section_id', $section_ids)
				->join('article_tag', 'article_tag.article_id', '=', 'article_section.article_id')
				->select('article_section.section_id as section_id', 'article_tag.tag_id as tag_id',
						DB::raw('count(*) as articles'))
				->groupBy('article_section.section_id', 'article_tag.tag_id')
				->get();
		foreach ($counts as $cnt){
			// Если есть строка - обновляем
			if(DB::table('section_tag')
					->where('section_id', $cnt->section_id)
					->where('tag_id', $cnt->tag_id)->exists()){
				DB::table('section_tag')
					->where('section_id', $cnt->section_id)
					->where('tag_id', $cnt->tag_id)
					->update(['articles' => $cnt->articles]);
			}
			// Нет - значит добавляем
			else{
				DB::table('section_tag')->insert((array) $cnt);
			}
		}
	}

	protected function updateMainBlog($article){
		// Выбираем все важные блоги, кроме текущей
		$query = Article::where('main_blog', '=', 1)
			->where('id', '<>', $article->id);
		// Если Проект не указан - статья юзера
		if($article->project_id == null){
			// Берем все статьи без Проекта от этого юзера
			$query->whereNull('project_id')
				->where('author_id', '=', $article->author_id);
		}
		// Иначе берем все статьи Проекта
		else{
			$query->where('project_id', '=', $article->project_id);
		}
		// Убираем признак важного блога
		$query->update(['main_blog' => 0]);
	}

	protected function updateTags($instance, $tags){
		foreach ($tags as $tag) {
			// Создаем или получаем тег
			$tag_id = is_array($tag)
					? array_get($tag, 'id', Tag::firstOrCreate(['value' => $tag['value']])->id)
					: $tag;
			// Добавляем инфу о юзере, который поставил тег
			$instance->tags()->attach([
				$tag_id => ['user_id' => Auth::user()->id]
			]);
		}
	}

	public function store() {

		$fields = ['enabled', 'project_id', 'comments_blocked', 'title', 'announce', 'content', 'main_blog'];
		$rules = [
			'enabled' => 'integer|required',
			'project_id' => 'integer',
			'comments_blocked' => 'boolean',
			'title' => 'required|max:90',
			'content' => 'required',
			'main_blog' => 'boolean',
			'tags' => 'array',
			'sections' => 'array',
		];
        $user = Auth::user();
		if (! (Auth::check())){ // TODO? && Auth::user()->can('article_create'))){ // TODO: проверять принадлежность к Проекту
			throw new UserException('Доступ запрещен');
		}
		$validator = Validator::make(Input::all(), $rules);
		if ($validator->fails()) {
			throw new InvalidInputException(implode("\n", $validator->messages()->all()));
		}
		$data = Input::only($fields);
		$data['author_id'] = Auth::user()->id;
        date_default_timezone_set('Europe/Moscow');
		$data['created'] = date("Y-m-d H:i:s");
		if(! array_get($data, 'comments_blocked')){
			$data['comments_blocked'] = 0;
		}
		// Если проставлено Важный блог, а статья выклчена - никакого Важного блога тогда
		if(! (array_get($data, 'main_blog') && array_get($data, 'enabled'))){
			$data['main_blog'] = 0;
		}
		$cutPos = mb_strpos($data['content'], '[cut]');
        if (mb_strlen($data['content'],'UTF-8') > 1000 && !$cutPos){
           $cutPos = 1000; 
        }
		$data['announce'] = $cutPos ? mb_substr($data['content'], 0, $cutPos) : $data['content'];
		$instance = Article::create($data);

		//Если установлены чекбоксы объявлений то закидываем информацию в таблицу announcements
		$announcement = Input::get('announcement');
		if (($announcement['global'] === true) || ($announcement['newbie']) === true) {
			if (Auth::user()->can('announcement_create')) {
				$new_announcement = new Announcement;
				$new_announcement->article_id = $instance->id;
				if ($announcement['global']) {
					$new_announcement->is_global = 1;
                    $new_announcement->expire_date = Carbon::now()->addDay($announcement['time'])->toDateTimeString();
				} else {
					$new_announcement->is_global = 0;
				}
				$new_announcement->expire_days = $announcement['time'];
				$new_announcement->save();
			} else {
				throw new UserException('Доступ запрещен');
			}
		}

		//
		// Если статья видима и является важным блогом
		if($instance->main_blog && $instance->enabled == 1){
			$this->updateMainBlog($instance);
		}
		if(Input::get('tags') && $user->canUseArticleTags()){
			$this->updateTags($instance, Input::get('tags'));
		}

		$sections = [Section::BLOGS_ID];
		if(Input::get('sections')){
			// Проверяем, что никто из разделов не выкинул Блоги
			// TODO: возможно, надо будет переделать это, когда будут объявления
			$sections = array_pluck(Input::get('sections'), 'id');
			if(! in_array(Section::BLOGS_ID, $sections)){
				$sections[] = Section::BLOGS_ID;
			}
		}
		$instance->sections()->sync($sections);
		$this->updateSectionsTags($instance->sections);

        $instance->moderation_status = $instance->getNewRecordModerationStatus();
        $instance->save();

		return $this->getInstance($instance->id);
	}

	public function update($id)
	{
		$sectionsTags = [];
		$updateMainBlog = false;
		$data = Input::all();
		$fields = [
			'enabled' => 'integer',
			'created' => 'date',
			'project_id' => 'integer',
			'comments_blocked' => 'boolean',
			'main_blog' => 'boolean'
		];
		$validator = Validator::make($data, $fields);
		if ($validator->fails()) {
			throw new InvalidInputException(implode("\n", $validator->messages()->all()));
		}

		$instance = Article::findOrFail($id);

        if (! (Auth::check() &&  ($this->hasProjectPerms($instance) || Auth::user()->can('article_edit')))){
            throw new UserException('Доступ запрещен');
        }

		// Если проставлено Важный блог, а статья выклчена - никакого Важного блога тогда
		if(! (array_get($data, 'main_blog') && array_get($data, 'enabled'))){
			$data['main_blog'] = 0;
		}
		else{
			// Иначе, если до этого статья не была важным блогом, то надо обновить важный блог
			$updateMainBlog = !($instance->main_blog && $instance->enabled == 1);
		}
		foreach ($instance->getFields() as $field) {
			if(! in_array($field, ['id', 'announce', 'positive_rate', 'negative_rate']) && isset($data[$field])){
				$instance->$field = $data[$field];
			}
		}
		$cutPos = mb_strpos($instance->content, '[cut]');
        if (mb_strlen($data['content'],'UTF-8') > 1000 && !$cutPos){
           $cutPos = 1000; 
        }
        if(array_get($data, 'enabled') == 0){
            $instance->public_on_mainpage = 0;
        }
        $instance->announce = $cutPos ? mb_substr($instance->content, 0, $cutPos) : $instance->content;

        $instance->moderation_status = $instance->getNewRecordModerationStatus();

		$instance->save();



		// Если надо обновить важный блог
		if($updateMainBlog){
			$this->updateMainBlog($instance);
		}
		if(Input::get('tags')){
			// Указываем разделы, в которых надо обновить счетчики тегов
			$sectionsTags = $instance->sections->toArray();
			// Детачим все теги, чтобы приаттачить их снова.
			// Почему - потому что sync не сохраняет инфу, кто их проставил
			$instance->tags()->detach();
			$this->updateTags($instance, Input::get('tags'));
		}
		// Модерам и админам можно править разделы
		if(Input::get('sections') && Auth::user()->can('article_moderate')){
			// Проверяем, что никто из разделов не выкинул Блоги
			// TODO: возможно, надо будет переделать это, когда будут объявления
			$sections = array_map(function ($item){
				return is_array($item) ? $item['id'] : $item;
			}, Input::get('sections'));
			if(! in_array(Section::BLOGS_ID, $sections)){
				$sections[] = Section::BLOGS_ID;
			}
			$sectionsTags = $instance->sections->toArray();
			$instance->sections()->sync($sections);
			$sectionsTags = $sectionsTags + $instance->sections->toArray();
		}

		$announcement = Input::get('announcement', false);
		if (($announcement['global'] === true) || ($announcement['newbie']) === true) {
			if (Auth::user()->can('announcement_create') || (Auth::user()->can('announcement_edit'))) {
				$new_announcement = Announcement::firstOrCreate(['article_id' => $instance->id]);
				$new_announcement->article_id = $instance->id;
				if ($announcement['global']) {
					$new_announcement->is_global = 1;
                    $new_announcement->expire_date = Carbon::now()->addDay($announcement['time'])->toDateTimeString();
				} else {
					$new_announcement->is_global = 0;
				}
				$new_announcement->expire_days = $announcement['time'];
				$new_announcement->save();

			} else {
				throw new UserException('Доступ запрещен');
			}
		} else {
			$existing_announcement = Announcement::where('article_id', '=', $instance->id)->first();
			if ($existing_announcement) {
				if (Auth::user()->can('announcement_delete')) {
					$existing_announcement->delete();
				} else {
					throw new UserException('Доступ запрещен');
				}
			}
		}


		// разделы, в которых надо обновить счетчики тегов
		if($sectionsTags){
			$this->updateSectionsTags($sectionsTags);
		}

        return $this->getInstance($id);
	}

	public function destroy()
	{
		if (! Auth::check()){
			throw new UserException('Доступ запрещен');
		}
		$ids = (array) Input::get('ids');

        return Article::removeByIds($ids);

	}

	public function getInstance($id)
	{
		$instance = Article::with(['tags',
            'sections' => function($q){
		        $q->with('parent');
            },
            'author', 'project', 'announcement'])->find($id);
		if(!$instance){
			App::abort(404);
		}
	    // Если статья скрыта пользователями ее видит только автор, модер или админ
        if ( $instance->enabled == -1
                && !(Auth::check() && ($this->hasProjectPerms($instance)
				|| Auth::user()->can('article_moderate')))) {
       	    App::abort(404);
        }
   	    // Если статья скрыта и юзер не авторизован, или не (автор, модер или админ)
        if ( $instance->enabled != 1
				&& !(Auth::check() && ($this->hasProjectPerms($instance)
				|| Auth::user()->can('article_moderate')))) {
			// То ему не положено видеть статью
 			return null;
		}
		$instance->formatArticleData();
		//Сюда загоняем 150 (плюс-минус) знаков с самого начала статьи. То есть примерно 1-3 полноценных предложения
        $key = md5('ArticleDescription' . $instance['title'] . $instance['announce']);
        $instance['meta_description'] = Cache::remember($key,15,function() use ($instance) {
            return SeoMetaHelper::makeDescription($instance['content'], '');
        });

        //Здесь нужно разместить 10 слов. Первые слова берем из тегов к статье. Остальные добиваем из текста как и раньше - самые часто встречающиеся
        $key = md5('ArticleKeywords' . $instance['title'] . $instance['announce']);
        $instance['meta_keywords'] = Cache::remember($key,15,function() use ($instance) {
            $str_tags = '';
            if(count($instance['tags'])>0){
                foreach ($instance['tags'] as $tag){
                    $str_tags .= $tag->value . ' ';
                }
            }
            return SeoMetaHelper::makeKeywords($str_tags, $instance['content'], array());
        });
		return $instance->toJson(JSON_NUMERIC_CHECK);
	}

	public function index() {
		return Article::getList(Input::all());
	}

	public function checkViewed($id) {
		$announcement = Announcement::findOrFail($id);
		$article = Article::findOrFail($announcement->article_id);

		$article->viewedBy()->save(User::findOrFail(Auth::id()), [], true);
	}

	public function getAnnouncements(){
		if(!Auth::check()){
			return null;
		}

		$announcements = Announcement::where('expire_date', '>', date("Y-m-d H:i:s"))
            ->orWhere('is_global','=', 0)
            ->orWhere('expire_days','=', 0)
            ->with(['article' => function ($q) {
                $q->with(['viewedBy' => function ($q) {
                    $q->select('user_id')->where('user_id', Auth::id());
                }])->moderated();
            }])
			->get();
        $parser = new JBBCode\Parser();
        $parser->addCodeDefinitionSet(new AppCodeDefinitionSet());
        foreach ($announcements as $announcement) {
            $content = str_replace("[cut]", '', $announcement->article->content);
            $content = str_replace("\n", '<br />', $content);
            $announcement->article->content = $parser->parse($content)->getAsHtml();
        }
        return $announcements->toJson(JSON_NUMERIC_CHECK);
	}

	/**
	 *  Получение последних 5 статей блога
	 *  Выбираются все статьи имеющие комментарии, если их меньше чем нужно, они доираются обычными статьями без комментариев
	 * @return string
	 */
	public function getDiscussed(){
        if(Input::get('limit')){
            $discussionsNeedShow = Input::get('limit');
        } else {
    		/** @var ArticleSettings $setting */
    		try{
    			$setting = ArticleSettings::where(['name' => 'discussions_limit'])->get()[0];
    			$discussionsNeedShow = $setting['value'];
    		}catch(ErrorException $e ){
    			App::abort(500, 'В настройках статей отсутствует опция "discussions_limit"');
    		}
        }

		return Article::getDiscussedModels($discussionsNeedShow);
	}

    /**
     * Настройки статьи из раздела модерации - опубликовать на главной
     * @return string
     */

	public function publicMainpage(){
        $id     = Input::get('id');
        $value  = Input::get('new_value');
        $article = Article::findOrFail($id);
        $article->public_on_mainpage = $value;
        /** @var Boolean $result */
        $result = $article->save();
        return json_encode(['status' => $result, 'response' => $article->getAttributes()], JSON_UNESCAPED_UNICODE);
    }
}