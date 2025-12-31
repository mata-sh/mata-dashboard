<div class="box p-3">
    <h3 class="title is-5 mb-3">Server Information</h3>

    <!-- CPU Load -->
    <div class="media mb-2">
        <div class="media-left">
            <span class="icon has-text-info">
                <i class="fas fa-microchip"></i>
            </span>
        </div>
        <div class="media-content">
            <p class="is-size-7 mb-1"><strong>CPU Load:</strong> {{ CPU }}</p>
        </div>
    </div>

    <!-- Memory Usage -->
    <div class="media mb-2">
        <div class="media-left">
            <span class="icon has-text-warning">
                <i class="fas fa-memory"></i>
            </span>
        </div>
        <div class="media-content">
            <p class="is-size-7 mb-1"><strong>Memory Usage:</strong> {{ RAM }} MB</p>
        </div>
    </div>

    <!-- Disk Space -->
    <div class="media">
        <div class="media-left">
            <span class="icon has-text-danger">
                <i class="fas fa-hdd"></i>
            </span>
        </div>
        <div class="media-content">
            <p class="is-size-7 mb-1"><strong>Free Disk Space:</strong> {{ SPACE }} GB</p>
        </div>
    </div>
</div>
