<?php

namespace App\Newsletter;

/** The address's domain takes no mail: nothing would reach it. */
final class UndeliverableAddress extends \RuntimeException
{
}
