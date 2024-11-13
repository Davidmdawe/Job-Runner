<?php

use Illuminate\Support\Facades\Log;
use App\Models\BackgroundJob; // Ensure you have the BackgroundJob model created
use Illuminate\Support\Facades\DB;

// Check if a job ID was passed
$jobId = $argv[1] ?? null;

if (empty($jobId)) {
    echo "Job ID is required.\n";
    exit(1);
}

// Fetch the job details from the database
$job = BackgroundJob::find($jobId);

if (!$job) {
    echo "Job not found.\n";
    exit(1);
}

$className = $job->job_class;
$methodName = $job->method;
$params = json_decode($job->parameters, true) ?? []; // Decode JSON parameters
$maxRetries = 3;
$retryCount = $job->retry_count;
$success = false;

while ($retryCount < $maxRetries && !$success) {
    try {
        if (empty($className) || empty($methodName)) {
            throw new InvalidArgumentException("Class name and method name are required.");
        }

        // Update job status to "running"
        $job->update(['status' => 'running', 'retry_count' => $retryCount]);

        // Dynamically instantiate the class and call the method with parameters
        $jobInstance = new $className();
        call_user_func_array([$jobInstance, $methodName], $params);

        // Log success message and update job status
        Log::info("Job executed successfully: $className@$methodName", [
            'params' => $params,
            'timestamp' => now(),
        ]);
        
        $job->update(['status' => 'completed']);
        $success = true; // Mark the job as successful

    } catch (Exception $e) {
        // Log error details
        Log::channel('background_jobs_errors')->error("Job failed: $className@$methodName", [
            'error' => $e->getMessage(),
            'params' => $params,
            'timestamp' => now(),
        ]);

        $retryCount++;
        $job->update(['retry_count' => $retryCount]);

        if ($retryCount < $maxRetries) {
            Log::info("Retrying job: $className@$methodName - Attempt $retryCount/$maxRetries", [
                'timestamp' => now(),
            ]);
            sleep(5); // Optional delay before retrying (e.g., 5 seconds)
        } else {
            // Update job status to "failed" after max retries
            $job->update(['status' => 'failed']);
        }
    }
}

if (!$success) {
    Log::channel('background_jobs_errors')->error("Job failed after $maxRetries retries: $className@$methodName", [
        'params' => $params,
        'timestamp' => now(),
    ]);
}
