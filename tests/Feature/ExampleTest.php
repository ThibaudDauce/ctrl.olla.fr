<?php

test('returns a successful response', function () {
    $this->get('/')->assertSuccessful();
});
