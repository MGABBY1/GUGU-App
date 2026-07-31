<?php
/** Legacy staff login POST — redirect to the single app login */
header('Location: /gugu-app/app/?login=1', true, 302);
exit;
