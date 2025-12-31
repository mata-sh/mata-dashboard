
<div id="poller" 
    hx-get="/watch/refresh/app" 
     hx-target="this" 
     hx-trigger="every 5s" 
     hx-swap="innerHtml">
     TEST2
</div>

<span id="app-status-{{ APP_ID }}" class="app-status" hx-get="/watch/modal" hx-target="body" hx-swap="beforeend" 
          data-tippy-content="{{ APP_STATUS_TOOLTIP | raw }}">
        <span class="app-status-count {{ APP_STATUS_TYPE }}">
        {% if APP_STATUS_COUNT %}{{ APP_STATUS_COUNT }}
        {% else %}{{ ICON_CHECK_CIRCLE | raw }}{% endif %}
        </span> 
        <span class="app-status-text {{ APP_STATUS_TYPE }}">        
            {{ APP_STATUS_TYPE }}
        </span>
    </span>
    <span class="app-filesize count {{ LOG_FILE_SIZE_LEVEL }}" data-tippy-content="{{ LOG_FILE_SIZE_TOOLTIP | raw }}">
        {{ MAX_LOG_FILE_SIZE }} {{ ICON_ALERT | raw }}
    </span>
    <span class="app-sessions count" data-tippy-content="{{ SESSION_TOOLTIP | raw }}">{{ SESSION_COUNT_LAST }} {{ ICON_USER | raw }}</span>
</div>
