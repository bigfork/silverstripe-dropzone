/* global document */
import Dropzone from 'dropzone';

const InitDropzoneField = (dropzoneFieldHolder) => {
  const container = dropzoneFieldHolder.querySelector('.js-dropzone');
  const input = dropzoneFieldHolder.querySelector('input[type="file"]');
  const schema = JSON.parse(input.attributes['data-schema'].value);
  const filesInputName = `${schema.name}[Files][]`;

  // Swap 'js-dropzone' for 'dropzone' - we use a different class to prevent autoDiscover
  // from running in the CMS and picking the class up (asset-admin also provides dropzone)
  container.classList.remove('js-dropzone');
  container.classList.add('dropzone');

  const dropzone = new Dropzone(container, schema.config);

  // Track how many file slots have been used by previously uploaded files. This is later used to
  // adjust the maxFiles setting when a previously uploaded file is removed
  let fileSlotsReserved = 0;

  // Add placeholder files representing existing file IDs
  const existingInputs = dropzoneFieldHolder.querySelectorAll(`.dropzone-placeholder input[name="${filesInputName}"]`);
  // IE doesn't like forEach on NodeList, so convert it to an array
  const existing = Array.prototype.slice.call(existingInputs);
  existing.forEach((existingFileInput) => {
    const mockFile = {
      name: existingFileInput.getAttribute('data-file-name'),
      size: existingFileInput.getAttribute('data-file-size'),
      upload: {
        uuid: existingFileInput.value // id of the file
      }
    };

    dropzone.emit('addedfile', mockFile); // Adds the file to the uploaded list
    dropzone.emit('complete', mockFile); // Hides progress bar
    dropzone.emit('success', mockFile); // Triggers success handler

    if (dropzone.options.maxFiles && dropzone.options.maxFiles > 0) {
      dropzone.options.maxFiles -= 1;
      fileSlotsReserved += 1;
    }
  });

  // On successful upload, add a hidden input containing the returned file ID
  const addHiddenInput = (file, response) => {
    const filesInput = document.createElement('input');
    filesInput.type = 'hidden';
    filesInput.name = filesInputName;
    filesInput.value = response.id;
    filesInput.setAttribute('data-uuid', file.upload.uuid);
    if (input.hasAttribute('form')) {
      filesInput.setAttribute('form', input.getAttribute('form'));
    }
    dropzoneFieldHolder.appendChild(filesInput);
  };

  // Handle both single and multiple uploads per HTTP request
  if (schema.config.uploadMultiple) {
    dropzone.on('successmultiple', (files, responses) => {
      files.forEach((file, i) => {
        addHiddenInput(file, responses[i]);
      });
    });
  } else {
    dropzone.on('success', (file, response) => {
      // because of https://gitlab.com/meno/dropzone/-/issues/231
      let res = response;
      if (res === '') {
        res = JSON.parse(file.xhr.response);
      }
      if (res !== undefined) {
        addHiddenInput(file, res[0]);
      }
    });
  }

  // When removing a file, its associated hidden input also needs to be removed
  dropzone.on('removedfile', (file) => {
    const filesInput = dropzoneFieldHolder.querySelector(`input[name="${filesInputName}"][data-uuid="${file.upload.uuid}"]`);
    if (filesInput) {
      filesInput.parentElement.removeChild(filesInput);

      if (fileSlotsReserved) {
        fileSlotsReserved -= 1;
        dropzone.options.maxFiles += 1;
      }
    }
  });
};

export default InitDropzoneField;
