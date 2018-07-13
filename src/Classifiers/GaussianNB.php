<?php

namespace Rubix\ML\Classifiers;

use Rubix\ML\Online;
use Rubix\ML\Persistable;
use Rubix\ML\Probabilistic;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use MathPHP\Statistics\Average;
use InvalidArgumentException;

class GaussianNB implements Multiclass, Online, Probabilistic, Persistable
{
    const TWO_PI = 2.0 * M_PI;

    /**
     * The weight of each class as a proportion of the entire training set.
     *
     * @var array
     */
    protected $weights = [
        //
    ];

    /**
     * The precomputed prior log probabilities of each label given by their weight.
     *
     * @var array
     */
    protected $priors = [
        //
    ];

    /**
     * The precomputed means of each feature column of the training set.
     *
     * @var array
     */
    protected $means = [
        //
    ];

    /**
     * The precomputed variances of each feature column of the training set.
     *
     * @var array
     */
    protected $variances = [
        //
    ];

    /**
     * The possible class outcomes.
     *
     * @var array
     */
    protected $classes = [
        //
    ];

    /**
     * Return the class prior log probabilities based on their weight over all
     * training samples.
     *
     * @return array
     */
    public function priors() : array
    {
        return $this->priors;
    }

    /**
     * Return the running mean of each feature column of the training data.
     *
     * @return array
     */
    public function means() : array
    {
        return $this->means;
    }

    /**
     * Return the running variances of each feature column of the training data.
     *
     * @return array
     */
    public function variances() : array
    {
        return $this->variances;
    }

    /**
     * Compute the necessary statistics to estimate a probability density for
     * each feature column.
     *
     * @param  \Rubix\ML\Datasets\Dataset  $dataset
     * @throws \InvalidArgumentException
     * @return void
     */
    public function train(Dataset $dataset) : void
    {
        if (!$dataset instanceof Labeled) {
            throw new InvalidArgumentException('This Estimator requires a'
                . ' Labeled training set.');
        }

        $this->classes = $dataset->possibleOutcomes();

        $this->weights = array_fill_keys($this->classes, 0);

        $this->priors = array_fill_keys($this->classes, 0.0);

        $this->means = $this->variances = array_fill_keys($this->classes,
            array_fill(0, $dataset->numColumns(), 0.0));

        $this->partial($dataset);
    }

    /**
     * Uupdate the rolling means and variances of each feature column using an
     * online updating algorithm.
     *
     * @param  \Rubix\ML\Datasets\Dataset  $dataset
     * @throws \InvalidArgumentException
     * @return void
     */
    public function partial(Dataset $dataset) : void
    {
        if (!$dataset instanceof Labeled) {
            throw new InvalidArgumentException('This Estimator requires a'
                . ' Labeled training set.');
        }

        if (in_array(self::CATEGORICAL, $dataset->columnTypes())) {
            throw new InvalidArgumentException('This estimator only works with'
                . ' continuous features.');
        }

        if (empty($this->weights)) {
            $this->train($dataset);
        }

        foreach ($dataset->stratify() as $class => $samples) {
            foreach (array_map(null, ...$samples) as $column => $values) {
                $n = count($values);

                $total = $this->weights[$class] + $n + self::EPSILON;

                $old = [
                    'mean' => $this->means[$class][$column],
                    'variance' => $this->variances[$class][$column],
                ];

                $mean = Average::mean($values);

                $ssd = 0.0;

                foreach ($values as $value) {
                    $ssd += ($value - $mean) ** 2;
                }

                $variance = $ssd / $total;

                $this->means[$class][$column] = (($n * $mean)
                    + ($this->weights[$class] * $old['mean']))
                    / $total;

                $ssd = ($this->weights[$class] * $old['variance']
                    + ($n * $variance)
                    + ($this->weights[$class] / ($n * $total))
                    * ($n * $old['mean'] - $n * $mean) ** 2);

                $this->variances[$class][$column] = $ssd / $total
                    + self::EPSILON;
            }

            $this->weights[$class] += count($samples);
        }

        $total = array_sum($this->weights) + self::EPSILON;

        foreach ($this->weights as $class => $weight) {
            $this->priors[$class] = log(($weight + self::EPSILON) / $total);
        }
    }

    /**
    * Calculate the likelihood of the sample being a member of a class and
    * chose the class with the highest likelihood score as the prediction.
     *
     * @param  \Rubix\ML\Datasets\Dataset  $dataset
     * @return array
     */
    public function predict(Dataset $dataset) : array
    {
        $predictions = [];

        foreach ($dataset as $sample) {
            $jll = $this->computeJointLogLikelihood($sample);

            $predictions[] = array_search(max($jll), $jll);
        }

        return $predictions;
    }

    /**
     * @param  \Rubix\ML\Datasets\Dataset  $dataset
     * @return array
     */
    public function proba(Dataset $dataset) : array
    {
        $probabilities = [];

        foreach ($dataset as $i => $sample) {
            $jll = $this->computeJointLogLikelihood($sample);

            $max = $this->logSumExp($jll);

            foreach ($jll as $class => $likelihood) {
                $probabilities[$i][$class] = exp($likelihood - $max);
            }
        }

        return $probabilities;
    }

    /**
     * Calculate the joint log likelihood of a sample being a member of each class.
     *
     * @param  array  $sample
     * @return array
     */
    protected function computeJointLogLikelihood(array $sample) : array
    {
        $likelihood = [];

        foreach ($this->classes as $class) {
            $score = $this->priors[$class];

            foreach ($sample as $column => $feature) {
                $mean = $this->means[$class][$column];
                $variance = $this->variances[$class][$column];

                $pdf = -0.5 * log(self::TWO_PI * $variance);
                $pdf -= 0.5 * (($feature - $mean) ** 2) / $variance;

                $score += $pdf;
            }

            $likelihood[$class] = $score;
        }

        return $likelihood;
    }

    /**
     * Compute the log of the sum of expenonetial probabilties.
     *
     * @param  array  $probabilities
     * @return float
     */
    protected function logSumExp(array $probabilities) : float
    {
        $sigma = 0.0;

        foreach ($probabilities as $probability) {
            $sigma += exp($probability);
        }

        return log($sigma);
    }
}
