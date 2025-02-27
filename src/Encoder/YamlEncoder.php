<?php

namespace Drupal\content_sync\Encoder;

use Drupal\Component\Serialization\Yaml;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * YAML encoder.
 */
class YamlEncoder implements EncoderInterface, DecoderInterface {

  /**
   * The formats that this Encoder supports.
   *
   * @var string
   */
  protected string $format = 'yaml';

  /**
   * Constructor.
   */
  public function __construct(
    protected Yaml $yaml,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function decode($data, $format, array $context = []) {
    return $this->yaml::decode($data);
  }

  /**
   * {@inheritDoc}
   */
  public function supportsDecoding($format) : bool {
    return $format === $this->format;
  }

  /**
   * {@inheritDoc}
   */
  public function encode($data, $format, array $context = []) : string {
    return $this->yaml::encode($data);
  }

  /**
   * {@inheritDoc}
   */
  public function supportsEncoding($format) : bool {
    return $format === $this->format;
  }

}
