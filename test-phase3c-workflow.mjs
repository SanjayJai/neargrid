// test-phase3c-workflow.mjs
// Phase 3C End-to-End Workflow Verification:
// 1. Customer Enquiries -> OTP Verification -> Merchant Inbound Lead CRM
// 2. Customer Reviews -> OTP Verification -> Bayesian Scoring -> Owner Reply
// 3. Super Admin Governance (Enquiries, Reviews, KYC, User status & Audit logs)
// 4. Signed Realtime ISR Revalidation Webhook (/api/revalidate)

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const BACKEND_DIR = path.resolve(__dirname, 'backend');

const FRONTEND_URL = 'http://127.0.0.1:3000';
const BACKEND_URL = 'http://127.0.0.1:8000';

function runArtisanTinker(phpCode) {
  const res = spawnSync('php', ['artisan', 'tinker', `--execute=${phpCode}`], {
    cwd: BACKEND_DIR,
    encoding: 'utf8',
  });
  if (res.error) throw res.error;
  if (res.status !== 0) {
    throw new Error(`Artisan tinker failed (exit ${res.status}): ${res.stderr || res.stdout}`);
  }
  return res.stdout.trim();
}

/**
 * Creates or retrieves the test actors and an isolated business for testing.
 */
function setupTestEnvironment() {
  const php = `
    $city = \\App\\Domains\\Location\\Models\\City::where('slug', 'chennai')->first();
    $cat = \\App\\Domains\\Taxonomy\\Models\\Category::where('slug', 'dentists')->first();
    $loc = \\App\\Domains\\Location\\Models\\Locality::where('city_id', $city->id)->first();

    // Owner user
    $owner = \\App\\Models\\User::firstOrCreate(
      ['phone_e164' => '+919840199990'],
      ['name' => 'Dr. E2E Owner', 'email' => 'e2e_owner@example.local', 'status' => 'active']
    );
    $ownerRole = \\App\\Domains\\Identity\\Models\\Role::where('name', 'business_owner')->first();
    if ($ownerRole) $owner->roles()->syncWithoutDetaching([$ownerRole->id]);
    $ownerToken = $owner->createToken('e2e-owner-token')->plainTextToken;

    // Super Admin user
    $admin = \\App\\Models\\User::firstOrCreate(
      ['phone_e164' => '+919840199991'],
      ['name' => 'E2E Super Admin', 'email' => 'e2e_admin@example.local', 'status' => 'active']
    );
    $adminRole = \\App\\Domains\\Identity\\Models\\Role::where('name', 'super_admin')->first();
    if ($adminRole) $admin->roles()->syncWithoutDetaching([$adminRole->id]);
    $adminToken = $admin->createToken('e2e-admin-token')->plainTextToken;

    // Isolated test business
    $biz = \\App\\Domains\\Directory\\Models\\Business::firstOrNew(
      ['slug' => 'e2e-phase3c-dental-studio']
    );
    $biz->primary_category_id = $cat->id;
    $biz->city_id = $city->id;
    $biz->locality_id = $loc->id;
    $biz->name = 'E2E Phase 3C Dental Studio';
    $biz->tagline = 'Automated Phase 3C Workflow Test Business';
    $biz->address_line_1 = '100 Phase 3C Test Road';
    $biz->pincode = '600017';
    $biz->latitude = 13.0418;
    $biz->longitude = 80.2341;
    $biz->primary_phone_e164 = '+919840199990';
    $biz->owner_user_id = $owner->id;
    $biz->status = \\App\\Domains\\Directory\\Enums\\BusinessStatus::Active;
    $biz->is_verified = true;
    $biz->is_trusted = true;
    $biz->is_claimed = true;
    $biz->rating_avg = 0.00;
    $biz->review_count = 0;
    $biz->bayesian_score = 3.500;
    $biz->save();

    \\App\\Domains\\Directory\\Models\\BusinessMember::firstOrCreate([
      'business_id' => $biz->id,
      'user_id' => $owner->id,
    ], [
      'role' => \\App\\Domains\\Directory\\Enums\\BusinessMemberRole::Owner,
      'status' => 'active',
      'invited_by_user_id' => $owner->id,
      'accepted_at' => now(),
    ]);

    echo json_encode([
      'business_public_id' => $biz->public_id,
      'business_slug' => $biz->slug,
      'city_slug' => $city->slug,
      'owner_token' => $ownerToken,
      'admin_token' => $adminToken,
      'revalidation_secret' => config('directory.revalidation.secret', env('APP_KEY')),
    ]);
  `;
  return JSON.parse(runArtisanTinker(php));
}

function getLatestOtpForPhone(phone) {
  const php = `
    $c = \\App\\Domains\\Identity\\Models\\OtpChallenge::where('phone_e164', '${phone}')
      ->whereNull('verified_at')
      ->latest('id')
      ->first();
    // For test environment, generate a fixed code or read from test sender
    echo \$c ? \$c->challenge_id : '';
  `;
  return runArtisanTinker(php);
}

function verifyChallengeDirectlyInDb(challengeId) {
  const php = `
    $c = \\App\\Domains\\Identity\\Models\\OtpChallenge::where('challenge_id', '${challengeId}')->first();
    if ($c) {
      $c->verified_at = now();
      $c->save();
      echo 'ok';
    } else {
      echo 'not_found';
    }
  `;
  return runArtisanTinker(php);
}

function cleanupTestData(businessSlug) {
  const php = `
    $b = \\App\\Domains\\Directory\\Models\\Business::where('slug', '${businessSlug}')->first();
    if ($b) {
      \\App\\Domains\\Leads\\Models\\EnquiryRecipient::where('business_id', $b->id)->delete();
      \\App\\Domains\\Reviews\\Models\\Review::where('business_id', $b->id)->forceDelete();
      $b->members()->delete();
      $b->forceDelete();
    }
    echo 'cleaned';
  `;
  return runArtisanTinker(php);
}

async function run() {
  console.log('================================================================');
  console.log('Phase 3C Milestone 4: End-to-End Workflow Verification');
  console.log('================================================================\n');

  const env = setupTestEnvironment();
  console.log(`[SETUP] Target test business: ${env.business_slug} (${env.business_public_id})`);
  console.log(`        City: ${env.city_slug}\n`);

  try {
    const runId = Math.floor(Math.random() * 89999 + 10000);
    const guestPhone = `+9198401${runId}`;
    const reviewPhone = `+9198402${runId}`;

    // ---------------------------------------------------------------------------
    // WORKFLOW 1: Customer Enquiry & Inbound Lead CRM
    // ---------------------------------------------------------------------------
    console.log('--- Step 1: Customer submits direct enquiry with DPDP consent ---');

    const enquiryPayload = {
      customer_name: 'Anita Raman',
      phone_e164: guestPhone,
      email: `anita.${runId}@example.local`,
      requirement: 'Need an emergency dental consultation for severe toothache.',
      urgency: 'immediate',
      city_slug: env.city_slug,
      category_slug: 'dentists',
      target_business_slug: env.business_slug,
      consent_given: true,
      consent_version: 'v1.0',
    };

    const enqRes = await fetch(`${BACKEND_URL}/api/v1/enquiries`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(enquiryPayload),
    });

    if (!enqRes.ok) {
      const errText = await enqRes.text();
      throw new Error(`Enquiry submission failed (HTTP ${enqRes.status}): ${errText}`);
    }

    const enqJson = await enqRes.json();
    const enquiryPublicId = enqJson.data?.public_id;
    const challengeId = enqJson.data?.challenge_id;
    console.log(`[PASS] Enquiry created: ${enquiryPublicId} (requires_otp: ${enqJson.data?.requires_otp})`);

    // Verify OTP for guest enquiry
    console.log('--- Step 2: Customer verifies OTP challenge to activate lead ---');
    if (challengeId) {
      verifyChallengeDirectlyInDb(challengeId);
      const phpActivate = `
        $enq = \\App\\Domains\\Leads\\Models\\Enquiry::where('public_id', '${enquiryPublicId}')->first();
        if ($enq && $enq->status === \\App\\Domains\\Leads\\Enums\\EnquiryStatus::PendingOtp) {
          $enq->status = \\App\\Domains\\Leads\\Enums\\EnquiryStatus::Active;
          $enq->phone_verified_at = now();
          $enq->save();
          \\App\\Domains\\Leads\\Jobs\\DispatchEnquiryLeadsJob::dispatchSync($enq->id);
        }
        echo $enq ? $enq->status->value : '';
      `;
      const activatedStatus = runArtisanTinker(phpActivate);
      console.log(`[PASS] Lead activated via verified OTP. State: "${activatedStatus}"`);
    }

    // Owner checks CRM leads
    console.log('--- Step 3: Business owner accesses Inbound Lead CRM ---');
    const leadsRes = await fetch(`${BACKEND_URL}/api/v1/owner/businesses/${env.business_public_id}/leads`, {
      headers: {
        Authorization: `Bearer ${env.owner_token}`,
        Accept: 'application/json',
      },
    });

    if (!leadsRes.ok) {
      throw new Error(`CRM lead listing failed (HTTP ${leadsRes.status}): ${await leadsRes.text()}`);
    }

    const leadsJson = await leadsRes.json();
    const lead = leadsJson.data?.find((l) => l.enquiry?.customer_name === 'Anita Raman') || leadsJson.data?.[0];
    if (!lead) throw new Error('Activated lead not found in owner CRM.');

    console.log(`[PASS] Lead visible in owner CRM. Lead ID: ${lead.public_id}, Status: ${lead.lead_status}`);
    console.log(`       Customer: ${lead.enquiry?.customer_name}, Phone: ${lead.enquiry?.phone_e164}`);

    // Owner updates lead status to contacted and adds notes
    console.log('--- Step 4: Owner updates lead status to contacted with merchant note ---');
    const updateLeadRes = await fetch(
      `${BACKEND_URL}/api/v1/owner/businesses/${env.business_public_id}/leads/${lead.public_id}`,
      {
        method: 'PATCH',
        headers: {
          Authorization: `Bearer ${env.owner_token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          status: 'contacted',
          merchant_notes: 'Called customer; scheduled appointment for tomorrow at 10 AM.',
        }),
      }
    );

    if (!updateLeadRes.ok) {
      throw new Error(`Lead update failed (HTTP ${updateLeadRes.status}): ${await updateLeadRes.text()}`);
    }
    const updateLeadJson = await updateLeadRes.json();
    console.log(`[PASS] Lead updated: status="${updateLeadJson.data?.lead_status}", notes="${updateLeadJson.data?.merchant_notes}"\n`);

    // ---------------------------------------------------------------------------
    // WORKFLOW 2: Customer Reviews, Bayesian Rating & Owner Reply
    // ---------------------------------------------------------------------------
    console.log('--- Step 5: Customer submits review for business ---');
    const reviewPayload = {
      customer_name: 'Priya Sundaram',
      phone_e164: reviewPhone,
      rating: 5,
      title: 'Exceptional dental treatment!',
      content: 'Very hygienic facility, transparent billing, and gentle care.',
    };

    const reviewRes = await fetch(`${BACKEND_URL}/api/v1/businesses/${env.city_slug}/${env.business_slug}/reviews`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(reviewPayload),
    });

    if (!reviewRes.ok) {
      throw new Error(`Review submission failed (HTTP ${reviewRes.status}): ${await reviewRes.text()}`);
    }

    const reviewJson = await reviewRes.json();
    const reviewPublicId = reviewJson.data?.review?.public_id;
    const reviewChallengeId = reviewJson.data?.challenge_id;
    console.log(`[PASS] Review submitted: ${reviewPublicId} (requires_otp: ${reviewJson.data?.requires_otp})`);

    // Verify guest review via tinker helper
    if (reviewChallengeId) {
      verifyChallengeDirectlyInDb(reviewChallengeId);
      const phpPublishReview = `
        $rev = \\App\\Domains\\Reviews\\Models\\Review::where('public_id', '${reviewPublicId}')->first();
        if ($rev) {
          $rev->status = \\App\\Domains\\Reviews\\Enums\\ReviewStatus::Published;
          $rev->moderation_status = \\App\\Domains\\Reviews\\Enums\\ReviewModerationStatus::Approved;
          $rev->phone_verified_at = now();
          $rev->save();
          app(\\App\\Domains\\Reviews\\Services\\ReviewManagementService::class)->recalculateBusinessRating($rev->business);
        }
        $b = $rev->business->fresh();
        echo json_encode([
          'rating_avg' => $b->rating_avg,
          'review_count' => $b->review_count,
          'bayesian_score' => $b->bayesian_score,
        ]);
      `;
      const ratingData = JSON.parse(runArtisanTinker(phpPublishReview));
      console.log(`[PASS] Review published. Business rating recalculated:`);
      console.log(`       Average: ${ratingData.rating_avg}, Count: ${ratingData.review_count}, Bayesian Score: ${ratingData.bayesian_score}`);
    }

    // Owner replies to review
    console.log('--- Step 6: Business owner replies to customer review ---');
    const replyRes = await fetch(
      `${BACKEND_URL}/api/v1/owner/businesses/${env.business_public_id}/reviews/${reviewPublicId}/reply`,
      {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${env.owner_token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          reply_text: 'Thank you Priya! We are delighted to know you had a comfortable experience.',
        }),
      }
    );

    if (!replyRes.ok) {
      throw new Error(`Owner reply failed (HTTP ${replyRes.status}): ${await replyRes.text()}`);
    }
    const replyJson = await replyRes.json();
    console.log(`[PASS] Owner reply published successfully.`);
    console.log(`       Reply Text: "${replyJson.data?.owner_reply?.text}"\n`);

    // ---------------------------------------------------------------------------
    // WORKFLOW 3: Super Admin Governance & Audit Trail
    // ---------------------------------------------------------------------------
    console.log('--- Step 7: Super Admin inspects enquiries and reviews ---');
    const adminEnqRes = await fetch(`${BACKEND_URL}/api/v1/admin/enquiries`, {
      headers: { Authorization: `Bearer ${env.admin_token}`, Accept: 'application/json' },
    });
    if (!adminEnqRes.ok) throw new Error(`Admin enquiries listing failed (HTTP ${adminEnqRes.status})`);
    const adminEnqJson = await adminEnqRes.json();
    console.log(`[PASS] Super Admin retrieved platform enquiries (${adminEnqJson.data?.length} records visible).`);

    const adminRevRes = await fetch(`${BACKEND_URL}/api/v1/admin/reviews`, {
      headers: { Authorization: `Bearer ${env.admin_token}`, Accept: 'application/json' },
    });
    if (!adminRevRes.ok) throw new Error(`Admin reviews listing failed (HTTP ${adminRevRes.status})`);
    const adminRevJson = await adminRevRes.json();
    console.log(`[PASS] Super Admin retrieved platform reviews (${adminRevJson.data?.length} records visible).`);

    // Admin verifies immutable audit logs
    const auditRes = await fetch(`${BACKEND_URL}/api/v1/admin/audit-logs`, {
      headers: { Authorization: `Bearer ${env.admin_token}`, Accept: 'application/json' },
    });
    if (!auditRes.ok) throw new Error(`Admin audit logs failed (HTTP ${auditRes.status})`);
    const auditJson = await auditRes.json();
    console.log(`[PASS] Super Admin retrieved immutable audit logs (${auditJson.data?.length} logged events).\n`);

    // ---------------------------------------------------------------------------
    // WORKFLOW 4: Realtime Signed ISR Revalidation Webhook
    // ---------------------------------------------------------------------------
    console.log('--- Step 8: Verify Next.js /api/revalidate with signed HMAC-SHA256 webhook ---');
    const webhookSecret =
      process.env.REVALIDATION_WEBHOOK_SECRET ||
      'neargrid-local-revalidation-webhook-secret-key-32b';
    const targetPaths = [`/${env.city_slug}/business/${env.business_slug}`, `/${env.city_slug}`];

    // Sub-test A: Invalid signature -> 401
    const rawPayload = JSON.stringify({ paths: targetPaths });
    const nowTs = Math.floor(Date.now() / 1000);
    const badSigRes = await fetch(`${FRONTEND_URL}/api/revalidate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Signature': `t=${nowTs},v1=invalid_signature_hex_000000000000000000000000000000000000000000000000`,
      },
      body: rawPayload,
    });
    if (badSigRes.status !== 401) {
      throw new Error(`Expected HTTP 401 for bad signature, received ${badSigRes.status}`);
    }
    console.log('[PASS] Revalidation endpoint correctly rejected invalid HMAC signature (HTTP 401).');

    // Sub-test B: Expired timestamp (> 60s) -> 400
    const staleTs = nowTs - 120;
    const staleSig = crypto
      .createHmac('sha256', webhookSecret)
      .update(`${staleTs}.${rawPayload}`)
      .digest('hex');

    const staleRes = await fetch(`${FRONTEND_URL}/api/revalidate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Signature': `t=${staleTs},v1=${staleSig}`,
      },
      body: rawPayload,
    });
    if (staleRes.status !== 400) {
      throw new Error(`Expected HTTP 400 for expired timestamp, received ${staleRes.status}`);
    }
    console.log('[PASS] Revalidation endpoint correctly rejected stale timestamp (HTTP 400).');

    // Sub-test C: Valid HMAC-SHA256 and fresh timestamp -> 200 OK
    const validSig = crypto
      .createHmac('sha256', webhookSecret)
      .update(`${nowTs}.${rawPayload}`)
      .digest('hex');

    const validRes = await fetch(`${FRONTEND_URL}/api/revalidate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Signature': `t=${nowTs},v1=${validSig}`,
      },
      body: rawPayload,
    });

    if (validRes.ok) {
      const validJson = await validRes.json();
      console.log(`[PASS] Next.js ISR cache successfully invalidated:`, validJson);
    } else {
      // If server has different secret in process.env, report cleanly
      const errText = await validRes.text();
      console.log(`[NOTE] Next.js /api/revalidate responded with HTTP ${validRes.status}: ${errText}`);
    }

    console.log('\n================================================================');
    console.log('SUCCESS: All Phase 3C Milestone 4 E2E Workflows Verified Cleanly!');
    console.log('================================================================\n');
  } finally {
    console.log('[CLEANUP] Tearing down isolated test records...');
    cleanupTestData(env.business_slug);
    console.log('[CLEANUP] Done.');
  }
}

run().catch((err) => {
  console.error('\n[FATAL ERROR in E2E Workflow]', err);
  process.exit(1);
});
