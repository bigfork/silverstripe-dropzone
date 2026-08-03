import { initDropzoneField } from '../components/DropzoneField/DropzoneField';

document.addEventListener('DOMContentLoaded', () => {
  Array.from(document.querySelectorAll('div.dropzonefield')).forEach((dropzoneFieldHolder) => {
    initDropzoneField(dropzoneFieldHolder);
  });
});
