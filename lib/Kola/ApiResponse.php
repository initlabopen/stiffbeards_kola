<?php

namespace Kola;

class ApiResponse
{
    protected $status;
    protected $data = null;

    public function __construct($status, $dataContent)
    {
        $data = json_decode($dataContent, true);
        if (is_array($data) && isset($data['successful'])) {
            $this->data = $data;
        }
        $this->status = $status;
    }

    public function isOk()
    {
        return (($this->status >= 200 && $this->status < 300) || $this->status == 304) && $this->isParsed() && $this->data['successful'];
    }
    public function isParsed()
    {
        return is_array($this->data);
    }

    public function getErrors()
    {
        return $this->data['errors'];
    }
    public function getErrorsAsString()
    {
        $msg = '';
        foreach ($this->data['errors'] as $field => $errors) {
            if (is_array($errors)) {
                foreach ($errors as $error) {
                    $msg .= "Поле '$field': $error\n";
                }
            } else {
                if ($field == 'resource') {
                    $msg .= "$errors\n";
                } else {
                    $msg .= "Поле '$field': $errors\n";
                }
            }
        }
        return $msg;
    }

    /** @return array */
    public function getData()
    {
        return $this->data;
    }

    public function getStatus()
    {
        return $this->status;
    }
}

