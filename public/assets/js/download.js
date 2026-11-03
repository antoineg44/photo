document.addEventListener('DOMContentLoaded', () => {

    const buttons = document.querySelectorAll('.download-folder');

    const panel = document.getElementById('zipProgress');
    const bar = document.getElementById('zipBar');
    const percent = document.getElementById('zipPercent');
    const files = document.getElementById('zipFiles');
    const message = document.getElementById('zipMessage');
    const cancel = document.getElementById('cancelZip');
    const download = document.getElementById('downloadZip');

    const csrf = document.querySelector(
        'input[name="csrf_token"]'
    )?.value;

    let currentJob = null;
    let timer = null;

    buttons.forEach(button => {

        button.addEventListener('click', async () => {

            if (currentJob) {
                return;
            }

            panel.hidden = false;
            download.hidden = true;

            message.textContent = 'Préparation du ZIP...';

            const form = new FormData();

            form.append('action', 'start');
            form.append('file', button.dataset.file);
            form.append('csrf_token', csrf);

            try {

                const response = await fetch('download.php', {
                    method: 'POST',
                    body: form
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Erreur ZIP');
                }

                currentJob = data.id;

                timer = setInterval(checkProgress, 1000);

                checkProgress();

            } catch (error) {

                message.textContent = error.message;
                currentJob = null;
            }
        });

    });

    async function checkProgress() {

        if (!currentJob) {
            return;
        }

        try {

            const response = await fetch(
                'download.php?action=status&id=' + encodeURIComponent(currentJob),
                { cache: 'no-store' }
            );

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Erreur de suivi');
            }

            bar.value = data.progress;

            percent.textContent = data.progress + ' %';

            files.textContent =
                data.files_done + ' / ' + data.files_total + ' fichiers';

            message.textContent = data.message;

            if (data.status === 'ready') {

                clearInterval(timer);

                download.href =
                    'download.php?action=download&id='
                    + encodeURIComponent(currentJob);

                download.hidden = false;

                cancel.hidden = true;

                currentJob = null;

            } else if (
                data.status === 'cancelled'
                || data.status === 'error'
            ) {

                clearInterval(timer);

                cancel.hidden = true;

                currentJob = null;
            }

        } catch (error) {

            clearInterval(timer);

            message.textContent = error.message;

            currentJob = null;
        }
    }

    cancel.addEventListener('click', async () => {

        if (!currentJob) {
            return;
        }

        const form = new FormData();

        form.append('action', 'cancel');
        form.append('id', currentJob);
        form.append('csrf_token', csrf);

        try {

            const response = await fetch('download.php', {
                method: 'POST',
                body: form
            });

            if (!response.ok) {
                throw new Error('Impossible d’annuler.');
            }

            message.textContent = 'Annulation demandée...';

            cancel.disabled = true;

            clearInterval(timer);

            const id = currentJob;

            const wait = setInterval(async () => {

                try {

                    const response = await fetch(
                        'download.php?action=status&id=' + encodeURIComponent(id),
                        { cache: 'no-store' }
                    );

                    const data = await response.json();

                    if (
                        data.status === 'cancelled'
                        || data.status === 'error'
                    ) {
                        clearInterval(wait);

                        currentJob = null;

                        message.textContent = data.message;

                        cancel.hidden = true;
                    }

                } catch (error) {
                    clearInterval(wait);
                    message.textContent = error.message;
                }

            }, 1000);

        } catch (error) {

            message.textContent = error.message;
            cancel.disabled = false;
        }
    });

});
