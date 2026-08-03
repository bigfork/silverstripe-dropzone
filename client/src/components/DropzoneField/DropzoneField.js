import { Dropzone } from '@deltablot/dropzone';

// Marks a field holder as having been initialised. entwine re-runs onmatch against elements that
// already match whenever a rule for their selector is (re)defined, so this guard is what stops a
// second Dropzone instance being attached to a field that already has one
const initialisedAttribute = 'data-dropzone-initialised';

// Classes Dropzone.js adds to its container element, which need clearing out again on destroy
const dropzoneStateClasses = ['dropzone', 'dz-clickable', 'dz-started', 'dz-drag-hover', 'dz-max-files-reached'];

/**
 * Replay the file records rendered into the field by DropzoneField.ss as Dropzone.js "mock" files,
 * so that previously attached files show up in the preview list
 */
const addExistingFiles = (dropzone, dropzoneFieldHolder, filesInputName) => {
  const existingInputs = dropzoneFieldHolder.querySelectorAll(`.dropzone-placeholder input[name="${filesInputName}"]`);
  let fileSlotsReserved = 0;

  Array.from(existingInputs).forEach((existingFileInput) => {
    const mockFile = {
      name: existingFileInput.getAttribute('data-file-name'),
      size: existingFileInput.getAttribute('data-file-size'),
      isPlaceholder: true, // Distinguishes these from files uploaded during this page's lifetime
      upload: {
        uuid: existingFileInput.value // id of the file
      }
    };

    dropzone.emit('addedfile', mockFile); // Adds the file to the uploaded list

    // Thumbnails are generated server-side by the template, and are only present for images
    const thumbnail = existingFileInput.getAttribute('data-file-thumbnail');
    if (thumbnail) {
      dropzone.emit('thumbnail', mockFile, thumbnail);
    }

    dropzone.emit('complete', mockFile); // Hides progress bar
    dropzone.emit('success', mockFile); // Triggers success handler

    if (dropzone.options.maxFiles && dropzone.options.maxFiles > 0) {
      dropzone.options.maxFiles -= 1;
      fileSlotsReserved += 1;
    }
  });

  return fileSlotsReserved;
};

/**
 * Initialise Dropzone.js on the given field holder. Returns the Dropzone instance, or null if the
 * holder has already been initialised
 */
export const initDropzoneField = (dropzoneFieldHolder) => {
  if (dropzoneFieldHolder.hasAttribute(initialisedAttribute)) {
    return null;
  }

  const container = dropzoneFieldHolder.querySelector('.js-dropzone');
  const input = dropzoneFieldHolder.querySelector('input[type="file"]');
  const schema = JSON.parse(input.attributes['data-schema'].value);
  const filesInputName = `${schema.name}[Files][]`;

  // Swap 'js-dropzone' for 'dropzone', so that nothing is styled as a drop area until JavaScript
  // has actually initialised the field
  container.classList.remove('js-dropzone');
  container.classList.add('dropzone');

  const dropzone = new Dropzone(container, schema.config);
  dropzoneFieldHolder.setAttribute(initialisedAttribute, '');

  // Track how many file slots have been used by previously uploaded files. This is later used to
  // adjust the maxFiles setting when a previously uploaded file is removed
  let fileSlotsReserved = addExistingFiles(dropzone, dropzoneFieldHolder, filesInputName);

  // Dropzone.js only maintains this class for files it uploaded itself, so a field whose slots are
  // all taken by previously attached files needs it applying by hand - otherwise it goes on
  // advertising a drop area that rejects everything dropped on it
  const updateMaxFilesReached = () => {
    const reached = dropzone.options.maxFiles != null && dropzone.options.maxFiles <= 0;
    container.classList.toggle('dz-max-files-reached', reached);
  };

  updateMaxFilesReached();

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
    if (!filesInput) {
      return;
    }

    filesInput.parentElement.removeChild(filesInput);

    // Files uploaded during this page's lifetime are counted by Dropzone.js itself, so only the
    // slots reserved above need handing back
    if (file.isPlaceholder && fileSlotsReserved) {
      fileSlotsReserved -= 1;
      dropzone.options.maxFiles += 1;
    }

    updateMaxFilesReached();
  });

  return dropzone;
};

/**
 * Tear down the Dropzone.js instance attached to the given field holder, leaving it in a state
 * where initDropzoneField() can be called on it again
 */
export const destroyDropzoneField = (dropzoneFieldHolder) => {
  const container = dropzoneFieldHolder.querySelector('.dropzone');
  const dropzone = container ? container.dropzone : null;
  if (!dropzone) {
    return;
  }

  // destroy() removes every file, which would otherwise take the hidden inputs holding this
  // field's value with it
  dropzone.off('removedfile');
  dropzone.destroy();

  container.classList.remove(...dropzoneStateClasses);
  container.classList.add('js-dropzone');
  container.innerHTML = '';
  dropzoneFieldHolder.removeAttribute(initialisedAttribute);
};
