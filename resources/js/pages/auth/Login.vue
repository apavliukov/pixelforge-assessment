<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store as demoLogin } from '@/routes/demoLogin';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Log in to your account',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    // The seeded accounts anyone may sign in as; empty unless demo sign-in is switched on.
    demoAccounts: {
        key: string;
        name: string;
        description: string;
    }[];
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="flex flex-col gap-4">
        <section v-if="demoAccounts.length > 0" class="grid gap-3">
            <p class="text-center text-sm text-muted-foreground">
                This is a demo. Look around without an account:
            </p>

            <Form
                v-for="account in demoAccounts"
                :key="account.key"
                v-bind="demoLogin.form(account.key)"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    variant="outline"
                    class="h-auto w-full flex-col items-start gap-0.5 py-2 text-left whitespace-normal"
                    :disabled="processing"
                >
                    <span>Sign in as {{ account.name }}</span>
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ account.description }}
                    </span>
                </Button>
            </Form>

            <p class="text-center text-sm text-muted-foreground">
                or sign in with your own account
            </p>
        </section>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-4"
        >
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot your password?
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Don't have an account?
                <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
            </div>
        </Form>
    </div>
</template>
