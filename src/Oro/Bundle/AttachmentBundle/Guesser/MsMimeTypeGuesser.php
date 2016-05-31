<?php

namespace Oro\Bundle\AttachmentBundle\Guesser;

use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;

use Oro\Component\PhpUtils\ArrayUtil;

class MsMimeTypeGuesser implements MimeTypeGuesserInterface
{
    /** @var array */
    protected $typesMap = [
        'd0cf11e0a1b11ae1' => [
            'msg' => 'application/vnd.ms-outlook',
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function guess($path)
    {
        $file = ArrayUtil::find(
            function (array $file) use ($path) {
                return $file['tmp_name']['file'] === $path;
            },
            $_FILES
        );

        if (!$file) {
            return null;
        }

        $extension = pathinfo($file['name']['file'], PATHINFO_EXTENSION);

        $handle = fopen($path, 'r');
        $bytes = bin2hex(fread($handle, 8));
        fclose($handle);

        if (!isset($this->typesMap[$bytes][$extension])) {
            return null;
        }

        return $this->typesMap[$bytes][$extension];
    }
}
