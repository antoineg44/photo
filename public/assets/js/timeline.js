document.addEventListener('DOMContentLoaded', function () {

    const galerie = document.getElementById('galerie');
    const slider = document.getElementById('timelineSlider');
    const timeline = document.getElementById('timeline');
    const dateDisplay = document.getElementById('timelineDate');

    if (!galerie || !slider || !timeline || !dateDisplay) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | RÉCUPÉRATION DES MÉDIAS
    |--------------------------------------------------------------------------
    */

    const medias = Array.from(
        galerie.querySelectorAll('.element.media')
    );

    const mediasDates = medias
        .map((element, index) => {

            const timestamp = Number(element.dataset.timestamp);

            return {
                element: element,
                timestamp: timestamp,
                index: index
            };

        })
        .filter(media =>
            Number.isFinite(media.timestamp)
            && media.timestamp > 0
        );

    if (mediasDates.length === 0) {
        timeline.style.display = 'none';
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURATION DU SLIDER
    |--------------------------------------------------------------------------
    */

    slider.min = 0;
    slider.max = mediasDates.length - 1;
    slider.value = 0;

    /*
    |--------------------------------------------------------------------------
    | FORMATAGE DE LA DATE
    |--------------------------------------------------------------------------
    */

    function formatDate(timestamp) {

        return new Date(timestamp * 1000)
            .toLocaleString('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
    }

    /*
    |--------------------------------------------------------------------------
    | AFFICHAGE DE LA DATE AU SURVOL
    |--------------------------------------------------------------------------
    */

    function updateDate(index) {

        const media = mediasDates[index];

        if (!media) {
            return;
        }

        dateDisplay.textContent = formatDate(
            media.timestamp
        );
    }

    /*
    |--------------------------------------------------------------------------
    | POSITIONNEMENT DANS LA GALERIE
    |--------------------------------------------------------------------------
    */

    function goToMedia(index) {

        const media = mediasDates[index];

        if (!media) {
            return;
        }

        updateDate(index);

        media.element.scrollIntoView({
            behavior: 'auto',
            block: 'center'
        });
    }

    /*
    |--------------------------------------------------------------------------
    | DÉPLACEMENT DU CURSEUR
    |--------------------------------------------------------------------------
    */

    slider.addEventListener('input', function () {

        const index = Number(slider.value);

        updateDate(index);

        goToMedia(index);

    });

    /*
    |--------------------------------------------------------------------------
    | SURVOL DE LA BARRE
    |--------------------------------------------------------------------------
    */

    timeline.addEventListener('mouseenter', function () {

        timeline.classList.add('active');

        updateDate(Number(slider.value));

    });

    timeline.addEventListener('mouseleave', function () {

        timeline.classList.remove('active');

    });

    /*
    |--------------------------------------------------------------------------
    | CLIC SUR LA BARRE
    |--------------------------------------------------------------------------
    */

    slider.addEventListener('change', function () {

        goToMedia(Number(slider.value));

    });

    /*
    |--------------------------------------------------------------------------
    | SYNCHRONISATION AVEC LE SCROLL DE LA PAGE
    |--------------------------------------------------------------------------
    */

    let scrollTimeout;

    window.addEventListener('scroll', function () {

        clearTimeout(scrollTimeout);

        scrollTimeout = setTimeout(function () {

            if (timeline.matches(':hover')) {
                return;
            }

            const position = window.innerHeight / 2;

            let closestIndex = 0;
            let closestDistance = Infinity;

            mediasDates.forEach(function (media, index) {

                const rect = media.element.getBoundingClientRect();

                const center = rect.top + rect.height / 2;

                const distance = Math.abs(center - position);

                if (distance < closestDistance) {

                    closestDistance = distance;
                    closestIndex = index;

                }

            });

            slider.value = closestIndex;

            updateDate(closestIndex);

        }, 100);

    }, { passive: true });

    /*
    |--------------------------------------------------------------------------
    | INITIALISATION
    |--------------------------------------------------------------------------
    */

    updateDate(0);

});
