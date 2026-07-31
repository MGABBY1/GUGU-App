<?php
/** GUGU — open the app; login only when guest taps Log in (session survives refresh) */
header('Location: /gugu-app/app/', true, 302);
exit;
