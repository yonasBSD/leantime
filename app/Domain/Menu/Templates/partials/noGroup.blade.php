<ul class="level-0 noGroup">
    @foreach($projects as $project)

        <li class="projectLineItem hasSubtitle {{ $currentProject == $project['id'] ? "active" : '' }}" >
            @include('menu::partials.projectLink')
            <div class="clear"></div>
        </li>
    @endforeach
</ul>
