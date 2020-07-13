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

  // Add placeholder files representing existing file IDs
  const existingInputs = dropzoneFieldHolder.querySelectorAll(`.dropzone-placeholder input[name="${filesInputName}"]`);
  // IE doesn't like forEach on NodeList, so convert it to an array
  const existing = Array.prototype.slice.call(existingInputs);
  existing.forEach((existingFileInput) => {
    const mockFile = {
      name: existingFileInput.getAttribute('data-file-name'),
      size: existingFileInput.getAttribute('data-file-size')
    };

    dropzone.emit('addedfile', mockFile); // Adds the file to the uploaded list
    dropzone.emit('complete', mockFile); // Hides progress bar
    dropzone.emit('success', mockFile); // Triggers success handler

    if (dropzone.options.maxFiles) {
      dropzone.options.maxFiles -= 1;
    }
  });

  // On successful upload, add a hidden input containing the returned file ID
  const addHiddenInput = (file, response) => {
    const filesInput = document.createElement('input');
    filesInput.type = 'hidden';
    filesInput.name = filesInputName;
    filesInput.value = response.id;
    filesInput.setAttribute('data-uuid', file.upload.uuid);
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
      if (response !== undefined) {
        addHiddenInput(file, response[0]);
      }
    });
  }

  // When removing a file, its associated hidden input also needs to be removed
  dropzone.on('removedfile', (file) => {
    const filesInput = dropzoneFieldHolder.querySelector(`input[name="${filesInputName}"][data-uuid="${file.upload.uuid}"]`);
    if (filesInput) {
      filesInput.parentElement.removeChild(filesInput);
    }
  });
};

export default InitDropzoneField;
