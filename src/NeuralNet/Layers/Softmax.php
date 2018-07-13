<?php

namespace Rubix\ML\NeuralNet\Layers;

use MathPHP\LinearAlgebra\Matrix;
use Rubix\ML\NeuralNet\Optimizers\Optimizer;
use InvalidArgumentException;

class Softmax implements Output
{
    /**
     * The unique class labels.
     *
     * @var array
     */
    protected $classes = [
        //
    ];

    /**
     * The L2 regularization parameter.
     *
     * @var float
     */
    protected $alpha;

    /**
     * The width of the layer. i.e. the number of neurons.
     *
     * @var int
     */
    protected $width;

    /**
     * The weight matrix.
     *
     * @var \MathPHP\LinearAlgebra\Matrix
     */
    protected $weights;

    /**
     * The memoized input matrix.
     *
     * @var \MathPHP\LinearAlgebra\Matrix
     */
    protected $input;

    /**
     * The memoized z matrix.
     *
     * @var \MathPHP\LinearAlgebra\Matrix
     */
    protected $z;

    /**
     * The memoized output activations matrix.
     *
     * @var \MathPHP\LinearAlgebra\Matrix
     */
    protected $computed;

    /**
     * The memoized gradient matrix.
     *
     * @var \MathPHP\LinearAlgebra\Matrix
     */
    protected $gradients;

    /**
     * The gradient descent optimizer.
     *
     * @var \Rubix\ML\NeuralNet\Optimizers\Optimizer|null
     */
    protected $optimizer;

    /**
     * @param  array  $classes
     * @param  float  $alpha
     * @throws \InvalidArgumentException
     * @return void
     */
    public function __construct(array $classes, float $alpha = 1e-4)
    {
        $classes = array_values(array_unique($classes));

        if (count($classes) < 2) {
            throw new InvalidArgumentException('The number of unique classes'
                . ' must be 2 or more.');
        }

        if ($alpha < 0) {
            throw new InvalidArgumentException('L2 regularization parameter'
                . ' must be 0 or greater.');
        }

        $this->classes = $classes;
        $this->alpha = $alpha;
        $this->width = count($classes);
    }

    /**
     * @return int
     */
    public function width() : int
    {
        return $this->width;
    }

    /**
     * Initialize the layer by fully connecting each neuron to every input and
     * generating a random weight for each parameter/synapse in the layer.
     *
     * @param  int  $prevWidth
     * @param  \Rubix\ML\NeuralNet\Optimizers\Optimizer  $optimizer
     * @return int
     */
    public function initialize(int $prevWidth, Optimizer $optimizer) : int
    {
        $weights = array_fill(0, $this->width,
            array_fill(0, $prevWidth, 0.0));

        $r = sqrt(6 / $prevWidth);

        for ($i = 0; $i < $this->width; $i++) {
            for ($j = 0; $j < $prevWidth; $j++) {
                $weights[$i][$j] = rand((int) (-$r * 1e8), (int) ($r * 1e8)) / 1e8;
            }
        }

        $this->weights = new Matrix($weights);

        $optimizer->initialize($this->weights);

        $this->optimizer = $optimizer;

        return $this->width;
    }

    /**
     * Compute the input sum and activation of each neuron in the layer and return
     * an activation matrix.
     *
     * @param  \MathPHP\LinearAlgebra\Matrix  $input
     * @return \MathPHP\LinearAlgebra\Matrix
     */
    public function forward(Matrix $input) : Matrix
    {
        $this->input = $input;

        $this->z = $this->weights->multiply($input);

        $outputs = $cache = [[]];

        foreach ($this->z->asVectors() as $i => $z) {
            for ($j = 0; $j < $z->getN(); $j++) {
                $cache[$i][$j] = exp($z[$j]);
            }

            $sigma = array_sum($cache[$i]);

            for ($j = 0; $j < $z->getN(); $j++) {
                $outputs[$j][$i] = $cache[$i][$j] / ($sigma + self::EPSILON);
            }
        }

        $this->computed = new Matrix($outputs);

        return $this->computed;
    }

    /**
     * Calculate the errors and gradients for each output neuron.
     *
     * @param  array  $labels
     * @return array
     */
    public function back(array $labels) : array
    {
        $errors = [[]];

        foreach ($labels as $i => $label) {
            foreach ($this->classes as $j => $class) {
                $expected = $class === $label ? 1.0 : 0.0;

                $errors[$j][$i] = ($expected - $this->computed[$j][$i])
                    + 0.5 * $this->alpha * array_sum($this->weights[$j]) ** 2;
            }
        }

        $errors = new Matrix($errors);

        $this->gradients = $errors->multiply($this->input->transpose());

        return [$this->weights, $errors];
    }

    /**
     * Return the computed activation matrix.
     *
     * @return \MathPHP\LinearAlgebra\Matrix
     */
    public function activations() : Matrix
    {
        return $this->computed;
    }

    /**
     * Update the parameters in the layer and return the magnitude of the step.
     *
     * @return float
     */
    public function update() : float
    {
        $steps = $this->optimizer->step($this->gradients);

        $this->weights = $this->weights->add($steps);

        return $steps->oneNorm();
    }

    /**
     * @return array
     */
    public function read() : array
    {
        return [
            'weights' => clone $this->weights,
        ];
    }

    /**
     * Restore the parameters of the layer.
     *
     * @param  array  $parameters
     * @return void
     */
    public function restore(array $parameters) : void
    {
        $this->weights = $parameters['weights'];
    }
}
