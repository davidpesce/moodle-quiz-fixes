<?php
// Working to delete renamed duplicates across the entire system (Courses)
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');

// CLI options
list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'force' => false,
    'limit' => 0, // Optional limit on number of questions to process
    'offset' => 0,
], [
    'h' => 'help',
    'f' => 'force',
    'l' => 'limit',
    'o' => 'offset'
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

// Display help
if ($options['help']) {
    $help = "CLI script to manage and delete renamed duplicate questions globally across all courses.

Options:
-h, --help             Print this help.
-f, --force            Skip confirmation (use with caution).
-l, --limit=INT        Limit the number of questions to process (0 = no limit).

Example:
\$ php moodle-duplicate-manager-cli.php
\$ php moodle-duplicate-manager-cli.php --force
\$ php moodle-duplicate-manager-cli.php --limit=100
";
    
    cli_writeln($help);
    exit(0);
}

$force = (bool)$options['force'];
$limit = (int)$options['limit'];
$offset = (int)$options['offset'];

// Function to safely check if a question can be deleted
function is_question_safe_to_delete($questionid) {
    global $DB;
    
    $result = ['safe' => true, 'message' => ''];
    
    // Check 1: Does it have attempts?
    
    $attemptcount = $DB->count_records('question_attempts', ['questionid' => $questionid]);
    //cli_writeln('Check 1: Does it have attempts: ' . $attemptcount);
    if ($attemptcount > 0) {
        return ['safe' => false, 'message' => "Question has $attemptcount attempts"];
    }
    
    // Check 2: Is it included in any quiz? (Moodle 4.5.2 approach)
    // First check if quiz_slots exists (should exist in 4.5.2)
    if ($DB->get_manager()->table_exists('quiz_slots')) {
        //cli_writeln('Check 2: Is it included in any quiz?');
        try {
            // For Moodle 4.x, quiz_slots should have a reference column but it varies
            // First, check the schema to find the appropriate column
            $tableColumns = $DB->get_columns('quiz_slots');
            
            // Different versions use different columns
            if (isset($tableColumns['questionid'])) {
                //cli_writeln('modern approach');
                // Modern approach
                $quizCount = $DB->count_records('quiz_slots', ['questionid' => $questionid]);
                if ($quizCount > 0) {
                    return ['safe' => false, 'message' => "Question is used in $quizCount quiz(zes)"];
                }
            } else if (isset($tableColumns['questionid'])) {
                // Alternate column name that might be used
                //cli_writeln('alternate approach');
                $quizCount = $DB->count_records('quiz_slots', ['questionid' => $questionid]);
                if ($quizCount > 0) {
                    return ['safe' => false, 'message' => "Question is used in $quizCount quiz(zes)"];
                }
            } else {
                //cli_writeln('failover approach');
                // If we can't determine the column, try a more direct approach - check if the question is associated with any quiz
                $sql = "SELECT COUNT(DISTINCT qs.quizid) 
                        FROM {quiz_slots} qs 
                        WHERE qs.id IN (
                            SELECT qsr.itemid 
                            FROM {question_references} qsr
                            WHERE qsr.questionbankentryid IN (
                                SELECT qv.questionbankentryid
                                FROM {question_versions} qv
                                WHERE qv.questionid = :questionid
                            )
                        )";
                
                $quizCount = $DB->count_records_sql($sql, ['questionid' => $questionid]);
                //cli_writeln('quiz count: ' . $quizCount);
                if ($quizCount > 0) {
                    return ['safe' => false, 'message' => "Question is used in $quizCount quiz(zes)"];
                }
            }
        } catch (Exception $e) {
            // If there's an error, log it but don't prevent deletion
            error_log("Quiz checking error for question {$questionid}: " . $e->getMessage());
        }
    }
    
    // Check 3: Is it a random question? (these are special and can cause issues)
    try {
        $question = $DB->get_record('question', ['id' => $questionid]);
        //cli_writeln('Check 3: Is it a random question?: ' . $question->qtype);
        if ($question && $question->qtype === 'random') {
            return ['safe' => false, 'message' => "Question is a random question and should not be deleted directly"];
        }
    } catch (Exception $e) {
        // If error, play it safe
        return ['safe' => false, 'message' => "Could not verify question type: " . $e->getMessage()];
    }
    
    // All checks passed
    return ['safe' => true, 'message' => "Question is safe to delete"];
}

cli_writeln("Processing duplicate questions globally across all courses");

// Find renamed duplicates
try {
    // Try modern Moodle approach first (3.x+)
    $sql = "SELECT 
                q.id,
                q.name,
                q.stamp,
                q.qtype,
                qc.name as category_name,
                c.id as course_id,
                c.shortname as course_shortname,
                (SELECT COUNT(*) FROM {question_attempts} qa WHERE qa.questionid = q.id) as attempts
            FROM 
                {question} q
            JOIN 
                {question_versions} qv ON qv.questionid = q.id
            JOIN 
                {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
            JOIN 
                {question_categories} qc ON qbe.questioncategoryid = qc.id
            LEFT JOIN 
                {context} ctx ON qc.contextid = ctx.id
            LEFT JOIN 
                {course} c ON (ctx.contextlevel = 50 AND ctx.instanceid = c.id)
            WHERE
                (q.name LIKE '%(duplicate %)'
                AND q.stamp LIKE 'dup%.%')
            ORDER BY
                c.id, q.name";

    if ($limit > 0) {
        $renamedQuestions = $DB->get_records_sql($sql, [], 0, $limit);
    } else {
        $renamedQuestions = $DB->get_records_sql($sql);
    }

    cli_writeln('Found: ' . count($renamedQuestions));
} catch (Exception $e) {
    // Fall back to older Moodle structure
    try {
        $sql = "SELECT 
                    q.id,
                    q.name,
                    q.stamp,
                    q.qtype,
                    qc.name as category_name,
                    c.id as course_id,
                    c.shortname as course_shortname,
                    (SELECT COUNT(*) FROM {question_attempts} qa WHERE qa.questionid = q.id) as attempts
                FROM 
                    {question} q
                JOIN 
                    {question_categories} qc ON qc.id = q.category
                LEFT JOIN 
                    {context} ctx ON qc.contextid = ctx.id
                LEFT JOIN 
                    {course} c ON (ctx.contextlevel = 50 AND ctx.instanceid = c.id)
                WHERE 
                    (q.name LIKE '%(duplicate %)'
                    OR q.stamp LIKE 'dup%.%')
                ORDER BY
                    c.id, q.name";
        
        if ($limit > 0) {
            $renamedQuestions = $DB->get_records_sql($sql, [], 0, $limit);
        } else {
            $renamedQuestions = $DB->get_records_sql($sql);
        }
    } catch (Exception $e) {
        cli_error('Error fetching renamed questions: ' . $e->getMessage());
    }
}

if (empty($renamedQuestions)) {
    cli_writeln("No renamed duplicate questions found in the system.");
    exit(0);
}

// Group by course for display
$questionsByCourse = [];
foreach ($renamedQuestions as $q) {
    $courseId = $q->course_id ? $q->course_id : 'system';
    $courseName = $q->course_id ? $q->course_shortname : 'System-level';
    
    if (!isset($questionsByCourse[$courseId])) {
        $questionsByCourse[$courseId] = [
            'name' => $courseName,
            'questions' => []
        ];
    }
    
    $questionsByCourse[$courseId]['questions'][] = $q;
}

// Process each question to determine if it's truly safe to delete
$safeQuestions = [];
$unsafeQuestions = [];

foreach ($renamedQuestions as $q) {
    $safetyCheck = is_question_safe_to_delete($q->id);
    $q->safety_message = $safetyCheck['message'];
    
    if ($safetyCheck['safe']) {
        $safeQuestions[] = $q;
    } else {
        $unsafeQuestions[] = $q;
    }
}

// Count summary
cli_writeln("");
cli_writeln("Summary:");
cli_writeln("- Total renamed duplicates found: " . count($renamedQuestions));
cli_writeln("- Safe to delete: " . count($safeQuestions));
cli_writeln("- Unsafe (in use): " . count($unsafeQuestions));
cli_writeln("- Found across " . count($questionsByCourse) . " courses/contexts");

// If no safe questions, exit
if (empty($safeQuestions)) {
    cli_writeln("\nNo questions are safe to delete. Exiting.");
    exit(0);
}

// Display safe questions grouped by course
cli_writeln("\nQuestions that can be safely deleted:");
foreach ($questionsByCourse as $courseId => $courseData) {
    $safeCount = 0;
    $safeCourseQuestions = [];
    
    // Filter safe questions for this course
    foreach ($courseData['questions'] as $q) {
        if (is_question_safe_to_delete($q->id)['safe']) {
            $safeCourseQuestions[] = $q;
            $safeCount++;
        }
    }
    
    if ($safeCount > 0) {
        cli_writeln("\nCourse: {$courseData['name']} (ID: {$courseId})");
        cli_writeln(str_pad("ID", 8) . str_pad("Type", 15) . str_pad("Name", 50) . "Category");
        cli_writeln(str_repeat("-", 100));
        
        foreach ($safeCourseQuestions as $q) {
            $truncatedName = (strlen($q->name) > 45) ? substr($q->name, 0, 42) . "..." : $q->name;
            cli_writeln(
                str_pad($q->id, 8) . 
                str_pad($q->qtype, 15) . 
                str_pad($truncatedName, 50) . 
                $q->category_name
            );
        }
    }
}

// Ask for confirmation before deletion
if (!$force) {
    cli_writeln("\nAre you sure you want to delete these " . count($safeQuestions) . " questions across " . count($questionsByCourse) . " courses/contexts? (y/N)");
    $confirmation = "yes";
    
    if (!$confirmation || !preg_match('/^y(es)?$/i', $confirmation)) {
        cli_writeln("Deletion canceled.");
        exit(0);
    }
}

// Process deletion
$deletedCount = 0;
$deletionErrors = [];
$totalToDelete = count($safeQuestions);

cli_writeln("\nDeleting questions:");

try {
    require_once($CFG->dirroot . '/question/engine/bank.php');
    
    foreach ($safeQuestions as $index => $q) {
        $progress = round(($index / $totalToDelete) * 100);
        cli_writeln("[" . str_pad($progress . "%", 5) . "] Deleting question {$q->id}: {$q->name}");
        
        try {
            question_delete_question($q->id);
            $deletedCount++;
        } catch (Exception $e) {
            $deletionErrors[] = "Failed to delete question ID {$q->id}: " . $e->getMessage();
            cli_writeln("    ERROR: " . $e->getMessage());
        }
    }
    
    cli_writeln("\nCompleted: Successfully deleted {$deletedCount} of {$totalToDelete} questions.");
} catch (Exception $e) {
    cli_error("Error during deletion: " . $e->getMessage());
}

// Show any errors
if (!empty($deletionErrors)) {
    cli_writeln("\nWarning: Some questions could not be deleted:");
    foreach ($deletionErrors as $error) {
        cli_writeln("- " . $error);
    }
}

// Display unsafe questions (for information)
if (!empty($unsafeQuestions)) {
    cli_writeln("\nThe following questions were NOT deleted (in use):");
    cli_writeln(str_pad("ID", 8) . str_pad("Type", 15) . str_pad("Course", 20) . "Reason");
    cli_writeln(str_repeat("-", 120));
    
    foreach ($unsafeQuestions as $q) {
        $courseName = $q->course_shortname ?: 'System-level';
        cli_writeln(
            str_pad($q->id, 8) . 
            str_pad($q->name, 50) . 
            str_pad($q->qtype, 15) . 
            str_pad($courseName, 20) . 
            $q->safety_message
        );
    }
}

exit(0);
