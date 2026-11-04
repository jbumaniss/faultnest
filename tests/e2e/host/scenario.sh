#!/usr/bin/env bash
# After run.sh: exercise HTTP, Console, Queue and mail through real processes. Runs inside the php container.
cd /tmp/host
log=storage/logs/laravel.log
: > "$log"
php artisan serve --host=0.0.0.0 --port=8000 >/tmp/serve.log 2>&1 &
sleep 2
echo "--- HTTP"; php -r 'echo @file_get_contents("http://127.0.0.1:8000/e2e/boom?secret=querytoken", false, stream_context_create(["http"=>["ignore_errors"=>true,"header"=>"Cookie: priv=cookievalue\r\nAuthorization: Bearer headertoken"]]))===false?"":"", $http_response_header[0],"\n";'
echo "--- Console"; php artisan e2e:boom >/dev/null 2>&1; echo "exit=$?"
echo "--- Queue"; php artisan e2e:dispatch; echo "dispatch exit=$?"
sleep 1
echo "--- Mail worker (2 alert jobs per event group expected)"
php artisan queue:work redis --stop-when-empty --tries=3 --sleep=0 > /tmp/worker.log 2>&1; tail -n 40 /tmp/worker.log | cut -c1-120
echo "--- mails in log: $(grep -c 'Subject: \[e2e-host\]' "$log")"; grep 'Subject: \[e2e-host\]' "$log" | cut -c1-120; grep -E '^To: ' "$log" | sort | uniq -c
echo "--- second occurrence must not re-mail (cooldown)"
php artisan e2e:boom >/dev/null 2>&1; php artisan queue:work redis --stop-when-empty --tries=3 --sleep=0 >/dev/null 2>&1; echo "mails now: $(grep -c 'Subject: \[e2e-host\]' "$log")"
echo "--- group counts include console and queue"
echo "--- leak check over log, state"; php -r 'echo @file_get_contents("http://127.0.0.1:8000/e2e/state");' > /tmp/state.json
php -r '$s=json_decode(file_get_contents("/tmp/state.json"),true); foreach($s["groups"] as $g) echo $g["count"]," x ",$g["level"]," ",substr($g["title"],0,60),"\n"; foreach($s["events"] as $e) echo $e["source"],"|",$e["type"],"|",json_encode($e["context"]),"|",$e["file"],"\n";'
php -r '$s=json_decode(file_get_contents("/tmp/state.json"),true); $f=array_count_values(array_column($s["events"],"source")); ksort($f); echo "sources: ",json_encode($f),"\n";'
grep -c 'console e2e failure' /tmp/state.json | sed 's/^/console captured: /'
grep -c 'queue e2e failure' /tmp/state.json | sed 's/^/queue captured: /'
grep -c 'explicit-job-context' /tmp/state.json | sed 's/^/queue explicit context stored: /'
for leak in querytoken cookievalue headertoken 127.0.0.1; do grep -c "$leak" /tmp/state.json | sed "s/^/state contains $leak: /"; done
echo "--- prune"; php artisan faultnest:prune --max-batches=1; echo "exit=$?"
echo "--- transport failure with real worker: unreachable SMTP must retry, fail, not recurse"
sed -i 's/^MAIL_MAILER=.*/MAIL_MAILER=smtp/' .env; echo 'MAIL_HOST=fnreview-no-such-host' >> .env; echo 'MAIL_PORT=2525' >> .env
sed -i 's/^FAULTNEST_MAIL_TO=.*/FAULTNEST_MAIL_TO=retry@example.test/' .env
sed -i "s/'backoff_seconds' => 60/'backoff_seconds' => 1/" config/faultnest.php
php artisan config:clear >/dev/null; php artisan config:cache >/dev/null
before=$(php artisan tinker --execute='echo DB::table("faultnest_groups")->count();' 2>/dev/null | tail -1)
php artisan tinker --execute='\FaultNest\Laravel\Facades\FaultNest::captureMessage("transport failure probe");' >/dev/null 2>&1
for i in 1 2 3 4 5 6; do timeout 30 php artisan queue:work redis --stop-when-empty --tries=3 --sleep=0 >> /tmp/worker2.log 2>&1; sleep 2; done
cut -c1-110 /tmp/worker2.log | grep -E 'FAIL|DONE'
echo "failed_jobs: $(php artisan tinker --execute='echo DB::table("failed_jobs")->count();' 2>/dev/null | tail -1)"
after=$(php artisan tinker --execute='echo DB::table("faultnest_groups")->count();' 2>/dev/null | tail -1)
echo "groups before=$before after=$after (expected +1 for the probe, none for transport failure)"
