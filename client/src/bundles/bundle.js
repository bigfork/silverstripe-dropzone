/* global document */
import InitDropzoneField from '../components/DropzoneField/DropzoneField';

document.addEventListener('DOMContentLoaded', () => {
  // IE doesn't like forEach on NodeList, so convert it to an array
  const dropzoneFields = Array.prototype.slice.call(document.querySelectorAll('div.dropzonefield'));

  dropzoneFields.forEach((dropzoneFieldHolder) => {
    InitDropzoneField(dropzoneFieldHolder);
  });
});
