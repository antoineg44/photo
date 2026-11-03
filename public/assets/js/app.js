const lightbox = document.getElementById('lightbox');
const imageGrande = document.getElementById('imageGrande');

const images = Array.from(
    document.querySelectorAll('[data-image="1"]')
);

let indexImage = 0;

function afficherImage(index) {

    if (!images.length) return;

    indexImage = (
        index + images.length
    ) % images.length;

    imageGrande.src = images[indexImage].href;

    lightbox.classList.add('active');
}

images.forEach((image, index) => {

    image.addEventListener('click', event => {

        event.preventDefault();

        afficherImage(index);

    });

});

document.getElementById('fermer')
    .addEventListener('click', () => {

        lightbox.classList.remove('active');

        imageGrande.src = '';

    });

document.getElementById('precedent')
    .addEventListener('click', () => {

        afficherImage(indexImage - 1);

    });

document.getElementById('suivant')
    .addEventListener('click', () => {

        afficherImage(indexImage + 1);

    });

document.addEventListener('keydown', event => {

    if (!lightbox.classList.contains('active')) return;

    if (event.key === 'Escape') {
        lightbox.classList.remove('active');
        imageGrande.src = '';
    }

    if (event.key === 'ArrowLeft') {
        afficherImage(indexImage - 1);
    }

    if (event.key === 'ArrowRight') {
        afficherImage(indexImage + 1);
    }

});
