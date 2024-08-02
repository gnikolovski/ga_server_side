/**
 * @file
 * Google Analytics - Server Side - view_item event handling.
 */

(function (drupalSettings) {

  'use strict';

  setTimeout(() => {
    fetch(drupalSettings.ga_server_side.view_item.url, {
      method: 'POST',
      cache: 'no-cache',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(drupalSettings.ga_server_side.view_item.data),
      credentials: 'same-origin'
    })
      .then(response => {
        if (!response.ok) {
          throw new Error('Google Analytics - Server Side: Failed to receive a valid response from the network.');
        }
        return response.json();
      })
      .then(data => {
        console.log('Google Analytics - Server Side. Success: ', data);
      })
      .catch(error => {
        console.error('Google Analytics - Server Side. Error: ', error);
      });
  }, 2500);

})(drupalSettings);
